<?php
// 限制只能通过命令行访问
if (php_sapi_name() !== 'cli') {
    die('此脚本只能通过命令行运行');
}

set_time_limit(0);// 设置脚本无限执行
ignore_user_abort(true);// 忽略用户中断
gc_enable();// 开启垃圾回收

include_once 'api/downloader.php';
include_once 'api/tools.php';

$file_dir = MAP_DIR;// 下载目录
$log_file = LOG_DIR.'downloader_daemon.log';// 手动日志文件
ini_set('log_errors', 1);//报错日志
ini_set('error_log', LOG_DIR.'downloader_daemon.log');//设置报错日志
$interval = 5;      // 定时计数器下载任务检查间隔(秒)
$pdo = conn_db();
$running = true;
// 每日更新相关变量 - 每日凌晨 3 点执行
$update_hour = 3;
$update_mark = LOG_DIR . '.daily_update';  // 持久化标记文件，防止重启重复执行
$web_host = getenv('WEB_HOST') ?: 'nginx'; // API 调用目标（Docker 内部网络）

declare( ticks = 1 );
pcntl_signal(SIGTERM, function () use (&$running) {
    $running = false;
});

add_log($log_file, 1,'===Downloader daemon started===');
while ($running) {
    //pdo检查
    while(!alive_db($pdo)){
        $pdo = null;
        gc_collect_cycles();
        $pdo = conn_db();
        sleep($interval);
    }
    // 每日更新检查 - 凌晨 3 点执行（用文件记录防重启重复）
    $current_date = date('Y-m-d');
    $current_hour = (int)date('H');
    $last_mark = @file_get_contents($update_mark);
    if ($current_hour === $update_hour && $current_date !== trim($last_mark)) {
        @file_put_contents($update_mark, $current_date);
        
        add_log($log_file, 1, '===Starting daily map update check===');
        
        // 调用 API 执行更新任务
        $api_url = "http://{$web_host}/api/map_manage.php?action=update_all";
        $response = @file_get_contents($api_url);
        
        if ($response === false) {
            add_log($log_file, 3, "Daily update API call failed: Unable to connect to {$api_url}");
        } else {
            $result = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                add_log($log_file, 3, "Daily update API response parse error: " . json_last_error_msg());
            } elseif (isset($result['success']) && $result['success']) {
                // 成功：记录 data 内容
                $data = $result['data'] ?? 'empty';
                add_log($log_file, 1, "Daily update success: " . json_encode($data));
            } else {
                // 失败：记录 message 内容
                $msg = $result['message'] ?? 'Unknown error';
                add_log($log_file, 2, "Daily update failed: " . $msg);
            }
        }
    }
    // pdo获取下载任务
    $task = fetch_download_task($pdo);
    if(!$task){
        // 没有下载任务，休眠后继续检查
        sleep($interval);
        continue;
    }
    //出现下载任务，日志记录并执行
    add_log($log_file, 1,"Downloading {$task['disk_safe']} from {$task['downlink']}");
    $result = download_with_progress($pdo,$task,$file_dir,$log_file);
    if($result['success']){
        add_log($log_file, 1,"Success Downloaded {$task['disk_safe']}\nFrom: {$task['downlink']}");
        downlaod_success_callback($pdo,$task);
    } else {
        add_log($log_file, 2,"Fail Download {$task['disk_safe']}\nFrom: {$task['downlink']}\nReason: {$result['message']}");
        downlaod_fail_callback($pdo,$task);
    }

}
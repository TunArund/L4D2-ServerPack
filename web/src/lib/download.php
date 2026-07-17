<?php
// 统一任务表 — tasks
//   type: 'download' (src=SteamURL → dst=本地.vpk) | 'upload' (src=本地.vpk → dst=COS key)
//   status: waiting → downloading/uploading → success/fail
// core.php 和数据访问函数已由 bootstrap.php 自动加载

function download_with_progress($pdo, $task, $dir, $log_file, $max_retries = 5)
{
    $log_fp = fopen($log_file, 'a+');
    if (!$log_fp) return array_error("无法打开日志文件: $log_file");

    safe_update_task_status($task['id'], 'downloading');

    $base_url = $task['src'];
    $save_path = $task['dst'];
    $task_id  = $task['id'];

    $save_dir = dirname($save_path);
    if (!is_dir($save_dir)) mkdir($save_dir, 0755, true);

    $attempt = 0; $success = false; $err_msg = ''; $http_code = 0;

    $resume_from = (file_exists($save_path) && ($sz = filesize($save_path)) > 0) ? $sz : 0;
    if ($resume_from > 0) {
        fwrite($log_fp, date('Y-m-d H:i:s') . " 发现未完成文件，大小 {$resume_from} 字节，将从断点续传\n");
    }

    while ($attempt < $max_retries && !$success) {
        $attempt++;
        $url = $base_url;
        if ($attempt > 1) $url .= (strpos($url, '?') === false ? '?' : '&') . '_r=' . mt_rand();

        $ch = curl_init($url);
        if (!$ch) { fclose($log_fp); return array_error("无法初始化curl"); }

        $fp = ($resume_from > 0) ? fopen($save_path, 'ab') : fopen($save_path, 'wb');
        if ($resume_from > 0) curl_setopt($ch, CURLOPT_RESUME_FROM, $resume_from);
        if (!$fp) { curl_close($ch); fclose($log_fp); return array_error("无法打开文件: $save_path"); }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_STDERR => $log_fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_LOW_SPEED_LIMIT => 10240,
            CURLOPT_LOW_SPEED_TIME => 15,
        ]);

        $lastUpdate = 0; $offset = $resume_from;
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function (
            $resource, float $dl_size, float $downloaded, float $ul_size, float $uploaded
        ) use ($task_id, &$lastUpdate, $offset) {
            $now = microtime(true);
            if ($dl_size <= 0 || ($now - $lastUpdate) <= 1) return;
            $lastUpdate = $now;
            $actual = $offset + (int)$downloaded;
            safe_update_task_progress($task_id, $actual, $offset + (int)$dl_size);
        });

        $success = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err_msg = curl_error($ch);
        curl_close($ch);
        if (is_resource($fp)) fclose($fp);

        if ($success && $http_code == 206) { fclose($log_fp); return array_success("下载成功（续传）"); }
        if ($success && $http_code == 200) {
            if ($resume_from > 0) {
                fwrite($log_fp, date('Y-m-d H:i:s') . " 服务器不支持断点续传，重新下载\n");
                unlink($save_path); $resume_from = 0; $success = false; $attempt--; continue;
            }
            fclose($log_fp); return array_success("下载成功");
        }
        if (file_exists($save_path)) $resume_from = filesize($save_path);
        $success = false;
        if ($attempt < $max_retries) {
            fwrite($log_fp, date('Y-m-d H:i:s') . " 第 {$attempt} 次失败 (HTTP {$http_code})，{$max_retries}s 后重试\n");
            sleep(min($attempt * 2, 10));
        }
    }
    fclose($log_fp);
    return array_error("下载失败，{$max_retries} 次尝试后仍未成功：HTTP {$http_code} {$err_msg}");
}

function download_success_callback($pdo, $task)
{
    $map_id = $task['map_id'];
    update_task_status($task['id'], 'success');
    update_map_status($map_id, 'active');
    $result = get_map_title($map_id);
    $title = $result['success'] ? $result['data'] : '';

    $result = get_user_ids_by_steam_id(
        get_map_steam_id($map_id)['data'] ?? 0
    );
    if (!$result['success']) return array_error($result['message']);
    $user_ids = $result['data'];
    if (!$user_ids) return array_error('没有查到任务关联用户');
    broadcast_messages($user_ids, '下载完成!', $title);
    return true;
}

function download_fail_callback($pdo, $task)
{
    $map_id = $task['map_id'];
    update_task_status($task['id'], 'fail');
    update_map_status($map_id, 'abandon');

    $result = get_map_title($map_id);
    $title = $result['success'] ? $result['data'] : '';

    $result = get_user_ids_by_steam_id(
        get_map_steam_id($map_id)['data'] ?? 0
    );
    if (!$result['success']) return array_error($result['message']);
    broadcast_messages($result['data'], '下载失败!', $title);
    return true;
}

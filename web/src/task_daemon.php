<?php
include_once __DIR__ . '/config.php';
// 限制只能通过命令行访问
if (php_sapi_name() !== 'cli') {
    die('此脚本只能通过命令行运行');
}

set_time_limit(0);
ignore_user_abort(true);
gc_enable();

include_once LIB_DIR . 'core.php';
include_once LIB_DIR . 'db.php';
include_once LIB_DIR . 'download.php';
include_once LIB_DIR . 'upload.php';

// ============================================================
// 运行时配置
// ============================================================
define('DAEMON_LOG', LOG_DIR . 'task_daemon.log');   // 基路径 → app/YYYY/MM/DD.log

$file_dir     = MAP_DIR;
$interval     = 5;       // 空闲时任务轮询间隔（秒）
$update_hour  = 3;       // 每日维护执行时刻
$update_mark  = LOG_DIR . '.daily_update';           // 防重启重复执行
$web_host     = getenv('WEB_HOST') ?: 'nginx';
$sider_token  = getenv('SIDECAR_TOKEN') ?: '';

/**
 * 刷新 PHP error_log 指向当日文件（主循环周期性调用，保证跨日轮转）
 */
function refresh_error_log(): void {
    ini_set('error_log', daily_log_path(DAEMON_LOG));
}
refresh_error_log();

// ============================================================
// 信号处理（优雅退出）
// ============================================================
$running = true;
declare(ticks = 1);
pcntl_signal(SIGTERM, function () use (&$running) {
    $running = false;
});

// ============================================================
// 辅助函数
// ============================================================

/**
 * 调用 map_manage.php API 并解析 JSON 响应
 *
 * @return array 始终包含 success 键：
 *               成功 → ['success' => true,  'data' => <response body>]
 *               失败 → ['success' => false, 'error' => 'connect'|'http'|'json', 'detail' => ...]
 */
function call_api(string $web_host, string $token, string $action): array {
    $url = "http://{$web_host}/api/map_manage.php?action=" . urlencode($action);
    if ($token !== '') {
        $url .= '&token=' . urlencode($token);
    }

    $ctx = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $ctx);

    if ($response === false) {
        $err = error_get_last();
        return array_error('connect: ' . ($err['message'] ?? 'unknown'));
    }

    // 检查 HTTP 状态码（通过 $http_response_header 魔术变量）
    $status_line = $http_response_header[0] ?? '';
    if (!preg_match('#^HTTP/\d\.\d\s+2\d\d#', $status_line)) {
        return array_error('http: ' . $status_line);
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return array_error('json: ' . json_last_error_msg());
    }

    return array_success($result);
}

/**
 * 确保数据库连接存活，断连则重试
 */
function ensure_db_alive(&$pdo, int $retry_interval): void {
    while (!alive_db($pdo)) {
        $pdo = null;
        gc_collect_cycles();
        $pdo = conn_db();
        add_log(DAEMON_LOG, 2, 'DB disconnected, reconnecting...');
        sleep($retry_interval);
    }
}

/**
 * 每日维护：地图更新 → COS 同步
 *
 * 仅在凌晨指定小时执行一次（持久化标记文件防重启重复）。
 * 所有实际操作通过 map_manage.php API 端点完成，daemon 自身只做编排和日志。
 */
function daily_maintenance(PDO $pdo, string $web_host, string $token, int $update_hour, string $mark_file): void {
    $current_date = date('Y-m-d');
    $current_hour = (int) date('H');
    $last_mark    = @file_get_contents($mark_file);

    if ($current_hour !== $update_hour || $current_date === trim($last_mark)) {
        return;
    }

    @file_put_contents($mark_file, $current_date);

    // ---- 1. 地图更新（复用 map_manage.php?action=update_all） ----
    add_log(DAEMON_LOG, 1, '=== Daily map update ===');

    $result = call_api($web_host, $token, 'update_all');

    if (!$result['success']) {
        add_log(DAEMON_LOG, 3, "Daily update API {$result['message']}: {$web_host}");
    } elseif (!empty($result['data']['success'])) {
        add_log(DAEMON_LOG, 1, 'Daily update done: ' . ($result['data']['data']['message'] ?? 'OK'));
    } else {
        add_log(DAEMON_LOG, 2, 'Daily update failed: ' . ($result['data']['message'] ?? 'unknown'));
    }

    // ---- 2. COS 同步（本地直接执行，不经过 HTTP API） ----
    add_log(DAEMON_LOG, 1, '=== COS sync ===');

    $result = run_cos_sync($pdo);

    if ($result['success']) {
        add_log(DAEMON_LOG, 1, 'COS sync done: ' . ($result['data']['message'] ?? 'OK'));
    } else {
        add_log(DAEMON_LOG, 2, 'COS sync failed: ' . ($result['message'] ?? 'unknown'));
    }
}

/**
 * 执行 COS 同步（上传 + 索引页 + 孤儿清理）
 *
 * @return array ['success' => bool, 'data' => [...]]
 */
function run_cos_sync(PDO $pdo): array {
    if (!cos_configured()) {
        return ['success' => false, 'message' => 'COS 未配置'];
    }

    $tasks   = cos_batch_create_tasks($pdo);
    $index   = cos_sync_index();
    $cleanup = cos_cleanup_orphans($pdo);

    $message = "COS 同步：已创建 {$tasks['created']} 个上传任务" . ($tasks['skipped'] > 0 ? "（{$tasks['skipped']} 个文件缺失）" : "") . " | 索引页 " . ($index['success'] ? '✓' : '✗') . " | 清理孤儿 {$cleanup['deleted']} 个";

    return [
        'success' => true,
        'data'    => [
            'message' => $message,
            'upload'  => $tasks,
            'index'   => $index,
            'cleanup' => $cleanup,
        ],
    ];
}

/**
 * 检查手动触发文件，存在则执行 COS 同步
 *
 * 触发文件位于 LOG_DIR 根，由 Web API 写入，daemon 处理完后删除。
 */
function process_manual_triggers(PDO $pdo): void {
    $trigger_file = LOG_DIR . '.cos_sync';

    if (!file_exists($trigger_file)) {
        return;
    }

    add_log(DAEMON_LOG, 1, '=== Manual COS sync triggered ===');
    $result = run_cos_sync($pdo);

    if ($result['success']) {
        add_log(DAEMON_LOG, 1, 'Manual COS sync done: ' . ($result['data']['message'] ?? 'OK'));
    } else {
        add_log(DAEMON_LOG, 2, 'Manual COS sync failed: ' . ($result['message'] ?? 'unknown'));
    }

    @unlink($trigger_file);
}

/**
 * 获取并处理一个下载任务
 *
 * @return bool true=处理了一个任务, false=无待处理任务
 */
/**
 * 获取下一个待处理任务（抢占式：download > upload）
 *
 * 调度优先级：
 *   1. download waiting     ← 新下载任务
 *   2. download downloading ← 下载中断续传
 *   3. upload waiting       ← 新上传任务
 *   4. upload uploading     ← 上传中断恢复（daemon 强制重启后）
 */
function fetch_next_task(PDO $pdo): ?array {
    // 1. download waiting
    $result = $pdo->query("SELECT * FROM tasks WHERE type='download' AND status='waiting' ORDER BY id ASC LIMIT 1");
    if ($result && ($task = $result->fetch(PDO::FETCH_ASSOC))) return $task;

    // 2. download downloading（断点续传）
    $result = $pdo->query("SELECT * FROM tasks WHERE type='download' AND status='downloading' ORDER BY id ASC LIMIT 1");
    if ($result && ($task = $result->fetch(PDO::FETCH_ASSOC))) return $task;

    // 3. upload waiting
    $result = $pdo->query("SELECT * FROM tasks WHERE type='upload' AND status='waiting' ORDER BY id ASC LIMIT 1");
    if ($result && ($task = $result->fetch(PDO::FETCH_ASSOC))) return $task;

    // 4. upload uploading（中断恢复：daemon 强制重启后重新处理卡死的上传任务）
    $result = $pdo->query("SELECT * FROM tasks WHERE type='upload' AND status='uploading' ORDER BY id ASC LIMIT 1");
    if ($result && ($task = $result->fetch(PDO::FETCH_ASSOC))) return $task;

    return null;
}

/**
 * 获取并处理一个任务（非抢占式：download > upload）
 *
 * @return bool true=处理了一个任务, false=无待处理任务
 */
function process_next_task(PDO $pdo, string $file_dir, string $log_file): bool {
    $task = fetch_next_task($pdo);
    if (!$task) {
        return false;
    }

    if ($task['type'] === 'upload') {
        add_log(DAEMON_LOG, 1, "Uploading {$task['disk_safe']} → COS {$task['dst']}");
        $result = process_upload_task($pdo, $task);

        if ($result['success']) {
            add_log(DAEMON_LOG, 1, "Upload ok: {$task['disk_safe']}");
        } else {
            add_log(DAEMON_LOG, 2, "Upload fail: {$task['disk_safe']} — {$result['message']}");
        }
    } else {
        add_log(DAEMON_LOG, 1, "Downloading {$task['disk_safe']} from {$task['src']}");
        $result = download_with_progress($pdo, $task, $file_dir, $log_file);

        if ($result['success']) {
            add_log(DAEMON_LOG, 1, "Download ok: {$task['disk_safe']}");
            download_success_callback($pdo, $task);
        } else {
            add_log(DAEMON_LOG, 2, "Download fail: {$task['disk_safe']} — {$result['message']}");
            download_fail_callback($pdo, $task);
        }
    }

    return true;
}

$pdo = conn_db();
add_log(DAEMON_LOG, 1, '=== Task daemon started ===');
$last_log_date = date('Y-m-d');
// ============================================================
// 主循环
// ============================================================
while ($running) {
    $today = date('Y-m-d');
    if ($today !== $last_log_date) {
        refresh_error_log();
        $last_log_date = $today;
    }

    ensure_db_alive($pdo, $interval);
    daily_maintenance($pdo, $web_host, $sider_token, $update_hour, $update_mark);
    process_manual_triggers($pdo);

    if (!process_next_task($pdo, $file_dir, daily_log_path(DAEMON_LOG))) {
        sleep($interval);
    }
}
// ============================================================
// 主循环
// ============================================================
add_log(DAEMON_LOG, 1, '=== Task daemon stopped ===');

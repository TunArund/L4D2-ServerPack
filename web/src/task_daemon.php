<?php
// 限制只能通过命令行访问
if (php_sapi_name() !== 'cli') {
    die('此脚本只能通过命令行运行');
}

set_time_limit(0);
ignore_user_abort(true);
gc_enable();

include_once 'api/tools.php';
include_once 'api/lib/downloader.php';
include_once 'api/lib/uploader.php';

// ============================================================
// 运行时配置
// ============================================================
define('DAEMON_LOG', LOG_DIR . 'task_daemon.log');   // 基路径 → app/YYYY/MM/DD.log

$file_dir     = MAP_DIR;
$daily_log    = daily_log_path(DAEMON_LOG);          // 当日实际文件，供 fopen / error_log 直接写入
$interval     = 5;       // 空闲时任务轮询间隔（秒）
$update_hour  = 3;       // 每日维护执行时刻
$update_mark  = LOG_DIR . '.daily_update';           // 防重启重复执行
$web_host     = getenv('WEB_HOST') ?: 'nginx';
$sider_token  = getenv('SIDECAR_TOKEN') ?: '';

ini_set('log_errors', 1);
ini_set('error_log', $daily_log);

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
 * @return array|null 解析后的 response body，失败时返回 null
 */
function call_api(string $web_host, string $token, string $action): ?array {
    $url = "http://{$web_host}/api/map_manage.php?action=" . urlencode($action);
    if ($token !== '') {
        $url .= '&token=' . urlencode($token);
    }

    $response = @file_get_contents($url);
    if ($response === false) {
        return null;
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $result;
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
function daily_maintenance(string $web_host, string $token, int $update_hour, string $mark_file): void {
    $current_date = date('Y-m-d');
    $current_hour = (int) date('H');
    $last_mark    = @file_get_contents($mark_file);

    if ($current_hour !== $update_hour || $current_date === trim($last_mark)) {
        return;
    }

    @file_put_contents($mark_file, $current_date);

    // ---- 1. 地图更新（复用 map_manage.php?action=trigger_update_all） ----
    add_log(DAEMON_LOG, 1, '=== Daily map update ===');

    $result = call_api($web_host, $token, 'trigger_update_all');

    if ($result === null) {
        add_log(DAEMON_LOG, 3, "Daily update API unreachable: {$web_host}");
    } elseif (!empty($result['success'])) {
        add_log(DAEMON_LOG, 1, 'Daily update done: ' . ($result['data']['message'] ?? 'OK'));
    } else {
        add_log(DAEMON_LOG, 2, 'Daily update failed: ' . ($result['message'] ?? 'unknown'));
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
            'upload'  => $upload,
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
    $trigger_file = LOG_DIR . '.trigger_cos_sync';

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
 *   2. download downloading ← 中断续传
 *   3. upload waiting       ← 下载全完成后才处理上传
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

    return null;
}

/**
 * 获取并处理一个任务（抢占式：download > upload）
 *
 * @return bool true=处理了一个任务, false=无待处理任务
 */
function process_next_task(PDO $pdo, string $file_dir, string $daily_log): bool {
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
        $result = download_with_progress($pdo, $task, $file_dir, $daily_log);

        if ($result['success']) {
            add_log(DAEMON_LOG, 1, "Download ok: {$task['disk_safe']}");
            downlaod_success_callback($pdo, $task);
        } else {
            add_log(DAEMON_LOG, 2, "Download fail: {$task['disk_safe']} — {$result['message']}");
            downlaod_fail_callback($pdo, $task);
        }
    }

    return true;
}

// ============================================================
// 主循环
// ============================================================
$pdo = conn_db();
add_log(DAEMON_LOG, 1, '=== Task daemon started ===');

while ($running) {
    ensure_db_alive($pdo, $interval);
    daily_maintenance($web_host, $sider_token, $update_hour, $update_mark);
    process_manual_triggers($pdo);

    if (!process_next_task($pdo, $file_dir, $daily_log)) {
        sleep($interval);
    }
}

add_log(DAEMON_LOG, 1, '=== Task daemon stopped ===');

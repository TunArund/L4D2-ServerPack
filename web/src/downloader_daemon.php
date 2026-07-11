<?php
// 限制只能通过命令行访问
if (php_sapi_name() !== 'cli') {
    die('此脚本只能通过命令行运行');
}

set_time_limit(0);
ignore_user_abort(true);
gc_enable();

include_once 'api/downloader.php';
include_once 'api/tools.php';
include_once 'api/cos_client.php';

// ============================================================
// 运行时配置
// ============================================================
define('DAEMON_LOG', LOG_DIR . 'downloader_daemon.log');   // 基路径 → app/YYYY/MM/DD.log

$file_dir     = MAP_DIR;
$daily_log    = daily_log_path(DAEMON_LOG);               // 当日实际文件，供 fopen / error_log 直接写入
$interval     = 5;       // 空闲时任务轮询间隔（秒）
$update_hour  = 3;       // 每日维护执行时刻
$update_mark  = LOG_DIR . '.daily_update';  // 防重启重复执行
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
 * 每日维护：地图更新检查 + COS 批量上传
 *
 * 仅在凌晨指定小时执行一次（持久化标记文件防重启重复）。
 */
function daily_maintenance(PDO $pdo, string $web_host, string $token, int $update_hour, string $mark_file): void {
    $current_date = date('Y-m-d');
    $current_hour = (int) date('H');
    $last_mark    = @file_get_contents($mark_file);

    if ($current_hour !== $update_hour || $current_date === trim($last_mark)) {
        return;
    }

    @file_put_contents($mark_file, $current_date);

    // ---- 1. 地图更新 ----
    add_log(DAEMON_LOG, 1, '=== Daily map update ===');

    $api_url = "http://{$web_host}/api/map_manage.php?action=update_all";
    if ($token !== '') {
        $api_url .= '&token=' . urlencode($token);
    }

    $response = @file_get_contents($api_url);

    if ($response === false) {
        add_log(DAEMON_LOG, 3, "Daily update API unreachable: {$api_url}");
    } else {
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            add_log(DAEMON_LOG, 3, 'Daily update JSON parse error: ' . json_last_error_msg());
        } elseif (!empty($result['success'])) {
            $summary = $result['data'] ?? [];
            $updated = count($summary['success'] ?? []);
            $failed  = substr_count($summary['fail'] ?? '', "\n");
            add_log(DAEMON_LOG, 1, "Daily update done: {$updated} updated, {$failed} failed");
        } else {
            add_log(DAEMON_LOG, 2, 'Daily update failed: ' . ($result['message'] ?? 'unknown'));
        }
    }

    // ---- 2. COS 批量上传（仅上传版本有变化的地图） ----
    if (!cos_configured()) {
        return;
    }

    add_log(DAEMON_LOG, 1, '=== COS batch upload ===');

    $stmt = $pdo->query(
        "SELECT id, disk_safe, version
         FROM maps
         WHERE status = 'active'
           AND (cos_version IS NULL OR cos_version != version)"
    );
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $uploaded = $skipped = $failed = 0;

    foreach ($pending as $map) {
        $local_path = MAP_DIR . $map['disk_safe'] . '.vpk';
        $cos_key    = $map['disk_safe'] . '.vpk';

        if (!file_exists($local_path)) {
            add_log(DAEMON_LOG, 2, "COS skip: file missing — {$map['disk_safe']}.vpk");
            $skipped++;
            continue;
        }

        $res = cos_upload_file($local_path, $cos_key);
        if ($res['success']) {
            try {
                $upd = $pdo->prepare("UPDATE maps SET cos_url = ?, cos_version = ? WHERE id = ?");
                $upd->execute([$res['data']['url'], $map['version'], $map['id']]);
            } catch (PDOException $e) {
                add_log(DAEMON_LOG, 3, "COS DB write failed map#{$map['id']}: " . $e->getMessage());
            }
            add_log(DAEMON_LOG, 1, "COS ok: {$map['disk_safe']}.vpk");
            $uploaded++;
        } else {
            add_log(DAEMON_LOG, 3, "COS fail: {$map['disk_safe']}.vpk — {$res['message']}");
            $failed++;
        }
    }

    add_log(DAEMON_LOG, 1, "COS batch done: {$uploaded} up, {$skipped} skip, {$failed} fail");
}

/**
 * 获取并处理一个下载任务
 *
 * @return bool true=处理了一个任务, false=无待处理任务
 */
function process_next_download_task(PDO $pdo, string $file_dir, string $daily_log): bool {
    $task = fetch_download_task($pdo);
    if (!$task) {
        return false;
    }

    add_log(DAEMON_LOG, 1, "Downloading {$task['disk_safe']} from {$task['downlink']}");
    $result = download_with_progress($pdo, $task, $file_dir, $daily_log);

    if ($result['success']) {
        add_log(DAEMON_LOG, 1, "Download ok: {$task['disk_safe']}");
        downlaod_success_callback($pdo, $task);
    } else {
        add_log(DAEMON_LOG, 2, "Download fail: {$task['disk_safe']} — {$result['message']}");
        downlaod_fail_callback($pdo, $task);
    }

    return true;
}

// ============================================================
// 主循环
// ============================================================
$pdo = conn_db();
add_log(DAEMON_LOG, 1, '=== Downloader daemon started ===');

while ($running) {
    ensure_db_alive($pdo, $interval);
    daily_maintenance($pdo, $web_host, $sider_token, $update_hour, $update_mark);

    if (!process_next_download_task($pdo, $file_dir, $daily_log)) {
        sleep($interval);
    }
}

add_log(DAEMON_LOG, 1, '=== Downloader daemon stopped ===');

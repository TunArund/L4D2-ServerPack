<?php
// 从 COS 桶恢复所有 .vpk 地图到 MAP_DIR（一次性灾备/迁移工具）
//
// 用法（在 task-daemon 容器内执行，COS_* 环境变量已由 compose 注入）:
//   php restore_from_cos.php             # 跳过本地已存在的地图
//   php restore_from_cos.php --dry-run   # 只列出桶内地图与缺失清单，不实际下载
//   php restore_from_cos.php --force     # 强制重新下载（覆盖本地已有文件）
//
// 说明：
//   - 桶内对象键的 basename 即本地文件名（与 cos_batch_create_tasks 上传时一致）；
//   - 已存在的本地文件默认跳过，中断的下载（.part）下次运行自动断点续传。

// 限制只能通过命令行访问
if (php_sapi_name() !== 'cli') {
    die('此脚本只能通过命令行运行');
}

set_time_limit(0);

include_once __DIR__ . '/../etc/config.php';
include_once LIB_DIR . 'cos.php';

$dry_run = in_array('--dry-run', $argv, true);
$force   = in_array('--force', $argv, true);

if (!cos_configured()) {
    fwrite(STDERR, "COS 未配置：请先设置 COS_SECRET_ID / COS_SECRET_KEY / COS_BUCKET 环境变量\n");
    exit(1);
}

if (!is_dir(MAP_DIR) && !mkdir(MAP_DIR, 0755, true) && !is_dir(MAP_DIR)) {
    fwrite(STDERR, '无法创建 MAP_DIR: ' . MAP_DIR . "\n");
    exit(1);
}

$fmt_size = function (int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1024, 1) . ' KB';
};

// ============================================================
// 1. 分页列出桶内所有 .vpk（marker 翻页，防截断遗漏）
// ============================================================
$keys   = [];
$marker = '';
do {
    $list = cos_list_objects('', '', 1000, $marker);
    if (!$list['success']) {
        fwrite(STDERR, "COS List 失败: {$list['message']}\n");
        exit(1);
    }
    foreach ($list['data']['files'] as $file) {
        if (preg_match('/\.vpk$/i', $file['key'])) {
            $keys[$file['key']] = (int)$file['size'];
        }
    }
    // 翻页游标：优先 NextMarker；缺失时退回本页最后一个 key（防死循环兜底）
    $marker = $list['data']['next_marker'] !== ''
        ? $list['data']['next_marker']
        : (!empty($list['data']['files']) ? $list['data']['files'][count($list['data']['files']) - 1]['key'] : '');
} while (!empty($list['data']['is_truncated']));

if (empty($keys)) {
    echo "桶内没有 .vpk 文件。\n";
    exit(0);
}

ksort($keys);

// ============================================================
// 2. 逐个下载
// ============================================================
$total   = count($keys);
$ok      = 0;
$skipped = 0;
$failed  = 0;
$bytes   = 0;
$i       = 0;

foreach ($keys as $key => $size) {
    $i++;
    $name  = basename($key);
    $local = MAP_DIR . $name;

    if (!$force && file_exists($local)) {
        $skipped++;
        echo "[{$i}/{$total}] 跳过 {$name}（本地已存在）\n";
        continue;
    }

    if ($dry_run) {
        echo "[{$i}/{$total}] 将下载 {$name}（{$fmt_size($size)}）\n";
        continue;
    }

    if ($force) {
        @unlink($local);
        @unlink($local . '.part');
    }

    echo "[{$i}/{$total}] 下载 {$name}（{$fmt_size($size)}）\n";

    $last = 0;
    $result = cos_download_file($key, $local, 3, function ($processed, $total_bytes) use (&$last, $name) {
        $now = microtime(true);
        if ($now - $last < 1) return;
        $last = $now;
        $pct = $total_bytes > 0 ? round($processed / $total_bytes * 100) : 0;
        printf("\r    %s: %d%%（%.1f / %.1f MB）    ", $name, $pct, $processed / 1048576, $total_bytes / 1048576);
    });
    echo "\n";

    if ($result['success']) {
        $ok++;
        $bytes += $size;
        echo "    ✓ 完成（{$fmt_size($result['data']['file_size'])}）\n";
    } else {
        $failed++;
        echo "    ✗ {$result['message']}\n";
    }
}

// ============================================================
// 3. 汇总
// ============================================================
echo "\n恢复完成：成功 {$ok}，跳过 {$skipped}，失败 {$failed}，共 {$total} 个地图，本次下载 {$fmt_size($bytes)}\n";
exit($failed > 0 ? 1 : 0);

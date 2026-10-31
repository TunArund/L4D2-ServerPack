<?php
// 统一任务表 — tasks
//   type: 'download' (src=SteamURL → dst=本地.vpk) | 'upload' (src=本地.vpk → dst=COS key)
//   status: waiting → downloading/uploading → success/fail
// core.php 和数据访问函数已由 bootstrap.php 自动加载

/**
 * 下载地图 vpk（流式 + 断点续传）
 *
 * 下载过程写入 {$save_path}.part，成功后 rename 覆盖正式文件——正式路径上的
 * 旧版本文件在下载完成前始终可用，也不会被误当作“未完成分片”续传。
 *
 * 断点续传约定（与 cos_download_file 一致）：
 *   - 只从 .part 续传，且 .part 必须属于同一个 src（用 .part.meta 记录，换版本即丢弃）；
 *   - 服务器忽略 Range 返回 200（或 libcurl 报 CURLE_RANGE_ERROR）时，丢弃 .part 重新下载；
 *   - 416 表示 .part 已不小于远端文件，直接丢弃重下；
 *   - 4xx 视为永久错误，删除 .part 并停止；5xx/网络错误保留 .part 等待重试。
 */
function download_with_progress($pdo, $task, $dir, $log_file, $max_retries = 5)
{
    $log_fp = fopen($log_file, 'a+');
    if (!$log_fp) return array_error("无法打开日志文件: $log_file");

    safe_update_task_status($task['id'], 'downloading');

    $base_url  = $task['src'];
    $save_path = $task['dst'];
    $task_id   = $task['id'];

    $save_dir = dirname($save_path);
    if (!is_dir($save_dir)) mkdir($save_dir, 0755, true);

    $part_path      = $save_path . '.part';
    $part_meta_path = $part_path . '.meta';

    // 旧的 .part 只能续传同一 src；换版本/换来源时全部丢弃，避免拼接出损坏文件
    if (file_exists($part_path)) {
        $meta = json_decode((string)@file_get_contents($part_meta_path), true);
        if (!is_array($meta) || ($meta['src'] ?? '') !== $base_url) {
            @unlink($part_path);
            @unlink($part_meta_path);
        }
    }
    if (!file_exists($part_path)) @unlink($part_meta_path);
    @file_put_contents($part_meta_path, json_encode(['src' => $base_url]));

    $attempt = 0; $success = false; $err_msg = ''; $http_code = 0; $resets = 0;

    while ($attempt < $max_retries && !$success) {
        $attempt++;
        $url = $base_url;
        if ($attempt > 1) $url .= (strpos($url, '?') === false ? '?' : '&') . '_r=' . mt_rand();

        // 每次尝试重新计算断点（.part 可能因上次尝试失败而增长）
        $resume_from = 0;
        if (file_exists($part_path)) {
            $sz = filesize($part_path);
            if ($sz !== false && $sz > 0) $resume_from = $sz;
        }
        if ($resume_from > 0) {
            fwrite($log_fp, date('Y-m-d H:i:s') . " 发现未完成文件 .part，大小 {$resume_from} 字节，将从断点续传\n");
        }

        $ch = curl_init($url);
        if (!$ch) { fclose($log_fp); return array_error("无法初始化curl"); }

        $fp = ($resume_from > 0) ? fopen($part_path, 'ab') : fopen($part_path, 'wb');
        if (!$fp) { curl_close($ch); fclose($log_fp); return array_error("无法打开文件: $part_path"); }

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
        if ($resume_from > 0) curl_setopt($ch, CURLOPT_RESUME_FROM, $resume_from);

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

        if ($success && $http_code == 206) break;

        // 服务器忽略 Range：200 响应会把完整内容续写进 .part，必须丢弃重下
        if ($http_code == 200 && $resume_from > 0) {
            if ($resets < 2 && @unlink($part_path)) {
                $resets++;
                fwrite($log_fp, date('Y-m-d H:i:s') . " 服务器拒绝断点续传 (HTTP 200)，丢弃 .part 重新下载\n");
                $success = false;
                $attempt--; // 重新下载不计入失败次数
                continue;
            }
            $success = false;
            $err_msg = '无法删除无效的 .part 文件';
            break;
        }
        if ($success && $http_code == 200) break;

        // 416：.part 已不小于远端文件（可能是旧版本残留），丢弃后完整重下
        if ($http_code == 416 && $resume_from > 0) {
            if ($resets < 2 && @unlink($part_path)) {
                $resets++;
                fwrite($log_fp, date('Y-m-d H:i:s') . " 断点超出远端文件 (HTTP 416)，丢弃 .part 重新下载\n");
                $success = false;
                $attempt--;
                continue;
            }
            $success = false;
            $err_msg = '无法删除无效的 .part 文件';
            break;
        }

        // 4xx 为永久错误，清掉可能已写入的错误响应体
        if ($http_code >= 400 && $http_code < 500) {
            @unlink($part_path);
            @unlink($part_meta_path);
            $success = false;
            break;
        }

        // 3xx/5xx 的响应体不是文件内容，回滚本次写入后再续传
        if ($http_code >= 300 && file_exists($part_path)) {
            $fp_trunc = fopen($part_path, 'r+b');
            if ($fp_trunc) { ftruncate($fp_trunc, $resume_from); fclose($fp_trunc); }
        }

        $success = false;
        if ($attempt < $max_retries) {
            fwrite($log_fp, date('Y-m-d H:i:s') . " 第 {$attempt} 次失败 (HTTP {$http_code})，{$max_retries}s 后重试\n");
            sleep(min($attempt * 2, 10));
        }
    }

    if (!$success || !file_exists($part_path)) {
        fclose($log_fp);
        return array_error("下载失败，{$max_retries} 次尝试后仍未成功：HTTP {$http_code} {$err_msg}");
    }

    if (!rename($part_path, $save_path)) {
        fclose($log_fp);
        return array_error("下载完成但无法写入目标文件: $save_path");
    }
    @unlink($part_meta_path);
    fclose($log_fp);
    return array_success($http_code == 206 ? "下载成功（续传）" : "下载成功");
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

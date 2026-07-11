<?php
// 统一任务表 — tasks
//   type: 'download' (src=SteamURL → dst=本地.vpk) | 'upload' (src=本地.vpk → dst=COS key)
//   status: waiting → downloading/uploading → success/fail
include_once 'tools.php';

function safe_execute(PDO &$pdo, string $query, array $params = [], int $retry = 3)
{
	for ($i = 0; $i < $retry; $i++) {
		try {
			$stmt = $pdo->prepare($query);
			$stmt->execute($params);
			return $stmt;
		} catch (PDOException $e) {
			error_log("数据库连接丢失，尝试重连..." . $e->getMessage());
			$pdo = conn_db();
		}
	}
	return false;
}

/**
 * 添加一个下载任务
 */
function add_download_task($pdo, $url, $disk_safe, $map_id)
{
	try {
		$result = safe_execute($pdo,
			"SELECT 1 FROM tasks WHERE map_id = ? AND type = 'download' AND status IN ('waiting', 'downloading')",
			[$map_id]);
		if ($result === false) return array_error("数据库错误");
		if ($result->rowCount() > 0) return array_error("已有相同任务");

		$dst = MAP_DIR . $disk_safe . '.vpk';
		$result = safe_execute($pdo,
			"INSERT INTO tasks (type, map_id, src, dst, disk_safe) VALUES ('download', ?, ?, ?, ?)",
			[$map_id, $url, $dst, $disk_safe]
		);
		if ($result === false) return array_error("数据库错误");
		return array_success($pdo->lastInsertId());
	} catch (PDOException $e) {
		return array_error($e->getMessage());
	}
}

/**
 * 下载文件（curl 流式 + 断点续传 + 进度回调）
 */
function download_with_progress($pdo, $task, $dir, $log_file, $max_retries = 5)
{
	$log_fp = fopen($log_file, 'a+');
	if (!$log_fp) return array_error("无法打开日志文件: $log_file");

	safe_execute($pdo, "UPDATE tasks SET status='downloading' WHERE id = ?", [$task['id']]);

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
		) use ($task_id, $pdo, &$lastUpdate, $offset) {
			$now = microtime(true);
			if ($dl_size <= 0 || ($now - $lastUpdate) <= 1) return;
			$lastUpdate = $now;
			$actual = $offset + (int)$downloaded;
			$pdo->prepare("UPDATE tasks SET processed_bytes = ?, total_bytes = ?, updated_at = NOW() WHERE id = ?")
			    ->execute([$actual, $offset + (int)$dl_size, $task_id]);
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

// 下载任务回调
function downlaod_success_callback($pdo, $task)
{
	$map_id = $task['map_id'];
	try {
		$pdo->prepare("UPDATE tasks SET status='success' WHERE id = ?")->execute([$task['id']]);
		$pdo->prepare("UPDATE maps SET status='active' WHERE id = ?")->execute([$map_id]);
		$stmt = $pdo->prepare("SELECT title FROM maps WHERE id = ?");
		$stmt->execute([$map_id]);
		$title = $stmt->fetch(PDO::FETCH_ASSOC)['title'];
	} catch (PDOException $e) { return false; }

	$result = fetch_related_users($pdo, $map_id);
	if (!$result['success']) return array_error($result['message']);
	$user_ids = $result['data'];
	if (!$user_ids) return array_error('没有查到任务关联用户');
	broadcast_message($user_ids, '下载完成!', $title);
	return true;
}

function downlaod_fail_callback($pdo, $task)
{
	$map_id = $task['map_id'];
	$pdo->prepare("UPDATE tasks SET status='fail' WHERE id = ?")->execute([$task['id']]);
	$pdo->prepare("UPDATE maps SET status='abandon' WHERE id = ?")->execute([$map_id]);

	$stmt = $pdo->prepare("SELECT title FROM maps WHERE id = ?");
	$stmt->execute([$map_id]);
	$title = $stmt->fetch(PDO::FETCH_ASSOC)['title'];

	$result = fetch_related_users($pdo, $map_id);
	if (!$result['success']) return array_error($result['message']);
	broadcast_message($result['data'], '下载失败!', $title);
	return true;
}

function fetch_related_users($pdo, $map_id)
{
	try {
		$steam_id = safe_execute($pdo, "SELECT steam_id FROM maps WHERE id = ?", [$map_id])->fetch(PDO::FETCH_COLUMN);
		$request_ids = safe_execute($pdo, "SELECT id FROM map_requests WHERE steam_id = ?", [$steam_id])->fetchAll(PDO::FETCH_COLUMN);
		$user_ids = [];
		foreach ($request_ids as $rid) {
			$u = safe_execute($pdo, "SELECT user_id FROM map_request_users WHERE id = ?", [$rid]);
			if ($u) $user_ids[] = $u->fetch(PDO::FETCH_COLUMN);
		}
		return array_success($user_ids);
	} catch (PDOException $e) { return array_error($e->getMessage()); }
}

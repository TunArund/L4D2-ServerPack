<?php
// CREATE TABLE download_tasks (
//     id INT AUTO_INCREMENT PRIMARY KEY,
//     map_id INT UNSIGNED NOT NULL,
//     downlink VARCHAR(256) NOT NULL,
//     disk_safe VARCHAR(256) NOT NULL,
//     status ENUM('waiting','downloading') DEFAULT 'waiting',
//     created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
//     FOREIGN KEY (`map_id`) REFERENCES `maps`(`id`)
// );
include_once 'tools.php';
//由于脚本需无限运行，需考虑垃圾回收与数据库连接断开问题
function safe_execute(PDO &$pdo, string $query, array $params = [], int $retry = 3)
{
	for ($i = 0; $i < $retry; $i++) {
		try {
			$stmt = $pdo->prepare($query);
			$stmt->execute($params);
			return $stmt;
		} catch (PDOException $e) { // 连接丢失，重连
			error_log("数据库连接丢失，尝试重连..." . $e->getMessage());
			$pdo = conn_db();
		}
	}
	return false;
}

/**
 * 添加一个下载任务
 * @return array ['success' => true, 'data' => task_id] or ['success' => false, 'error' => error_message]
 */
function add_download_task($pdo, $url, $disk_safe, $map_id)
{
	try {
		//检查是否有相同任务（map_id=same && status=waiting | downloading）
		$result = safe_execute($pdo, "SELECT * FROM `download_tasks` WHERE `map_id` = ? AND `status` IN ('waiting', 'downloading')", [$map_id]);
		if ($result === false) return array_error("数据库错误");
		if ($result->rowCount() > 0) return array_error("已有相同任务");
		//插入新任务
		$result = safe_execute(
			$pdo,
			"INSERT INTO download_tasks (map_id,downlink,disk_safe) VALUES (?, ?, ?)",
			[$map_id, $url, $disk_safe]
		);
		if ($result === false) return array_error("数据库错误");
		return array_success($pdo->lastInsertId());
	} catch (PDOException $e) {
		return array_error($e->getMessage());
	}
}
/**
 * 获取一个下载任务
 * @return array|false $task=[id, map_id, downlink, disk_safe, status]
 */
function fetch_download_task($pdo)
{
	// 优先查询 waiting 状态
	$result = safe_execute($pdo, "SELECT * FROM `download_tasks` WHERE `status` = 'waiting' ORDER BY id ASC");
	if ($result === false) return false;
	$task = $result->fetch(PDO::FETCH_ASSOC);
	// 如果没有任务，再查询 downloading
	if ($task === false) {
		$result = safe_execute($pdo, "SELECT * FROM `download_tasks` WHERE `status` = 'downloading' ORDER BY id ASC");
		if ($result === false) return false;

		$task = $result->fetch(PDO::FETCH_ASSOC);
		if ($task === false) return false;
	}

	return $task;
}
/**
 * 下载一个文件并更新数据库
 * @param string $url
 * @param string $save_path
 * @param int $task_id
 * @param PDO $pdo
 */
function download_with_progress($pdo, $task, $dir, $log_file, $max_retries = 5)
{
	$log_fp = fopen($log_file, 'a+');
	if (!$log_fp) {
		return array_error("无法打开日志文件: $log_file");
	}
	// 标记为 downloading
	$result = safe_execute($pdo, "UPDATE download_tasks SET status='downloading' WHERE id = ?", [$task['id']]);
	if ($result === false) return array_error('数据库错误：download_with_progress无法更新下载任务状态');
	$base_url = $task['downlink'];
	$save_path = $dir . $task['disk_safe'] . '.vpk';

	// 确保目标目录存在
	$save_dir = dirname($save_path);
	if (!is_dir($save_dir)) {
		mkdir($save_dir, 0755, true);
	}

	$attempt = 0;
	$success = false;
	$err_msg = '';
	$http_code = 0;

	while ($attempt < $max_retries && !$success) {
		$attempt++;

		// 重试时删除部分文件 + 加随机参数绕过 CDN 缓存获取新节点
		$url = $base_url;
		if ($attempt > 1) {
			if (file_exists($save_path)) unlink($save_path);
			$url .= (strpos($url, '?') === false ? '?' : '&') . '_r=' . mt_rand();
		}

		$ch = curl_init($url);
		if (!$ch) {
			fclose($log_fp);
			return array_error("无法初始化curl");
		}

		$fp = fopen($save_path, 'wb');

		curl_setopt($ch, CURLOPT_FILE, $fp);
		curl_setopt($ch, CURLOPT_STDERR, $log_fp);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_NOPROGRESS, false);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 0);
		curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 10240);
		curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 15);   // 15s 低速即中断（原 30s）

		// 进度回调
		$lastUpdate = 0;
		$task_id = $task['id'];
		curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function (
			$resource,
			float $download_size,
			float $downloaded,
			float $upload_size,
			float $uploaded
		) use ($task_id, $pdo, &$lastUpdate) {
			$now = microtime(true);
			if ($download_size <= 0 || ($now - $lastUpdate) <= 1) return;
			$lastUpdate = $now;
			$stmt = $pdo->prepare("UPDATE download_tasks SET
			downloaded_bytes = ?, total_bytes = ?, updated_at = NOW() WHERE id = ?
			");
			try {
				$stmt->execute([
					(int)$downloaded,
					(int)$download_size,
					$task_id
				]);
			} catch (PDOException $e) {
				return false;
			}
		});

		$success = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err_msg = curl_error($ch);

		if (is_resource($fp)) {
			fclose($fp);
		}

		if ($success && ($http_code == 200 || $http_code == 206)) {
			fclose($log_fp);
			return array_success("下载成功（第 $attempt 次尝试）");
		}

		$success = false;
		if ($attempt < $max_retries) {
			sleep(min($attempt * 2, 10)); // 递增退避: 2s → 4s → 6s → 8s → 10s
		}
	}

	fclose($log_fp);
	return array_error("下载失败，尝试 $max_retries 次后仍未成功。最后错误：$http_code $err_msg");
}


/**
 * 运行一个下载任务(已弃用，使用上方的download_with_progress)
 * @param string $dir /down/load/dir/
 * @param string $log >> $log_file
 * 
 */
function execute_download_task($pdo, $task, $dir, $log_file)
{
	$url = $task['downlink'];
	$filename = $task['disk_safe'] . '.vpk';
	$save_path = $dir . $filename;
	$task_id = $task['id'];
	$log_file =  LOG_DIR . 'wget/' . $task_id . $task['disk_safe'] . '.log';
	// 标记为 downloading
	$result = safe_execute($pdo, "UPDATE download_tasks SET status='downloading' WHERE id = ?", [$task_id]);
	if ($result === false) return false;
	// 执行 wget 并重定向输出
	$cmd = "wget -O " . escapeshellarg($save_path) . " " . escapeshellarg($url) . " >> " . escapeshellarg($log_file) . " 2>&1";
	//下载大文件会阻塞非常长时间
	exec($cmd, $output, $status);
	//返回下载状态
	return $status === 0 && file_exists($save_path);
}

// 获取任务关联的用户task->steam_id->地图->用户
function fetch_related_users($pdo, $map_id)
{
	try {
		$result = safe_execute($pdo, "SELECT steam_id FROM maps WHERE id = ?", [$map_id]);
		if ($result === false) return array_error("数据库错误");
		$steam_id = $result->fetch(PDO::FETCH_COLUMN);
		//2.获取地图请求
		$result = safe_execute($pdo, "SELECT id FROM map_requests WHERE steam_id = ?", [$steam_id]);
		if ($result === false) return array_error("数据库错误");
		$request_ids = $result->fetchAll(PDO::FETCH_COLUMN);
		//3.获取用户id
		$user_ids = [];
		foreach ($request_ids as $request_id) {
			$result = safe_execute($pdo, "SELECT user_id FROM map_request_users WHERE id = ?", [$request_id]);
			if ($result === false) continue;
			$user_ids[] = $result->fetch(PDO::FETCH_COLUMN);
		}
		return array_success($user_ids);
	} catch (PDOException $e) {
		return array_error($e->getMessage());
	}
}
// 下载任务成功回调
function downlaod_success_callback($pdo, $task)
{
	$map_id = $task['map_id'];
	try {
		//更新任务task状态success
		$stmt = $pdo->prepare("UPDATE download_tasks SET status='success' WHERE id = ?");
		$stmt->execute([$task['id']]);
		//更新maps表map状态active
		$stmt = $pdo->prepare("UPDATE maps SET status='active' WHERE id = ?");
		$stmt->execute([$map_id]);
		//获取地图名
		$stmt = $pdo->prepare("SELECT title FROM maps WHERE id = ?");
		$stmt->execute([$map_id]);
		$title = $stmt->fetch(PDO::FETCH_ASSOC)['title'];
	} catch (PDOException $e) {
		return false;
	}
	//向所有关联用户通知
	//1.获取用户id
	$result = fetch_related_users($pdo, $map_id);
	if (!$result['success']) return array_error($result['message']);
	$user_ids = $result['data'];
	if (!$user_ids) return array_error('没有查到任务关联用户，请检查数据库');
	//2.向相关用户广播消息
	broadcast_message($user_ids, '下载完成!', $title);
	return true;
}
function downlaod_fail_callback($pdo, $task)
{
	$map_id = $task['map_id'];
	//更新任务状态为fail
	$stmt = $pdo->prepare("UPDATE download_tasks SET status='fail' WHERE id = ?");
	$stmt->execute([$task['id']]);
	//更新地图状态为abandon
	$stmt = $pdo->prepare("UPDATE maps SET status='abandon' WHERE id = ?");
	$stmt->execute([$task['map_id']]);
	//获取地图名
	$stmt = $pdo->prepare("SELECT title FROM maps WHERE id = ?");
	$stmt->execute([$task['map_id']]);
	$title = $stmt->fetch(PDO::FETCH_ASSOC)['title'];
	//向所有关联用户通知
	//1.获取用户id
	$result = fetch_related_users($pdo, $map_id);
	if (!$result['success']) return array_error($result['message']);
	$user_ids = $result['data'];
	//2.向用户发送消息
	broadcast_message($user_ids, '下载失败!', $title);
	return true;
}

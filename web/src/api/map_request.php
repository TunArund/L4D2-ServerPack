<?php
// config / core / auth 已由 bootstrap.php 自动加载
// Content-Type: application/json 已由 json_error/json_success 自动设置
include_once LIB_DIR . 'map.php';

//检查登录
if (!check_login()) json_error('请先登录。');
//设置报错日志（按日轮转）
ini_set('log_errors', 1);
ini_set('error_log', daily_log_path(LOG_DIR . 'map_request_error.log'));

$pdo = conn_db();
$action = $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$is_admin = check_admin();

// CSRF：仅 POST 写入操作需要验证（GET 只读操作自动跳过）
if (!verify_csrf()) {
	json_error('CSRF 验证失败，请刷新页面重试。');
}

switch ($action) {
	case 'add':
		$steam_id = get_GET('steam_id', 1, null);
		if (!$steam_id) json_error('非法steam_id');
		$result = add_request($pdo, $user_id, $steam_id);
		if ($result['success']) json_success($result['data']);
		json_error($result['message']);
		exit;
	case 'delete':
		$request_id = get_GET('request_id', 1, null);
		if (!$request_id) json_error('非法request_id');
		$result = delete_request($pdo, $is_admin, $user_id, $request_id);
		if (!$result['success']) json_error($result['message']);
		json_success($result['data']);
		exit;
	case 'list':
		$limit    = get_GET('limit', 1, 10);
		$offset   = get_GET('offset', 1, 0);
		$order_by = get_GET('order_by', 0, 'id');
		$order    = get_GET('order', 0, 'ASC');
		$result   = list_request($pdo, $is_admin, $user_id, $limit, $offset, $order_by, $order);
		if (!$result['success']) json_error($result['message']);
		json_success($result['data']);
		exit;
	case 'count':
		$result = count_request($pdo);
		if (!$result['success']) json_error($result['message']);
		json_success($result['data']);
		exit;
	case 'approve':
		if (!$is_admin) json_error('权限不足');
		$result = post_ids();
		if (!$result['success']) json_error($result['message']);
		$success = 0; $fail = 0; $msg = '';
		foreach ($result['data'] as $request_id) {
			$r = approve_request($pdo, intval($request_id));
			if ($r['success']) { $success++; }
			else { $fail++; error_log($r['message']); $msg .= $r['message']; }
		}
		json_success("成功批准 $success 个，失败 $fail 个 错误信息：$msg");
		exit;
	default:
		json_error('未知操作');
}

<?php
// config / core / auth 已由 bootstrap.php 自动加载
// Content-Type: application/json 已由 json_error/json_success 自动设置

include_once LIB_DIR . 'steam.php';

function add_request(int $user_id, int $steam_id): array
{
    if (!$steam_id) return array_error('非法steam_id');
    $db_item = find_in_maps_or_requests($steam_id);
    if (!$db_item) {
        $result = build_map_request($steam_id);
        if (!$result['success']) return array_error('构造请求失败' . $result['message']);
        $map_request = $result['data'];
        delete_requests_by_steam_id($steam_id);
        $result = insert_request($map_request);
        if (!$result['success']) return array_error('插入数据库失败' . $result['message']);
        $map_request['id'] = $result['data'];
        $result = bind_request_user($map_request['id'], $user_id);
        if (!$result['success']) return array_error('绑定用户失败' . $result['message']);
        unset($map_request['description']);
        return array_success($map_request);
    } else switch ($db_item['status']) {
        case 'pending':
            $result = bind_request_user($db_item['id'], $user_id);
            if (!$result['success']) return array_error('绑定用户失败' . $result['message']);
            $result = find_request_by_id($db_item['id']);
            if (!$result['success']) return array_error($result['message']);
            $map_request = $result['data'];
            unset($map_request['description']);
            return array_success($map_request);
        case 'updating':
            return array_error("{$db_item['title']}正在更新！");
        case 'active':
            return array_error("{$db_item['title']}已被收录!");
        case 'abandon':
            $map_request = [
                'steam_id' => $steam_id, 'status' => 'pending', 'in_maps' => true,
                'map_id' => $db_item['id'], 'title' => $db_item['title'],
                'size' => $db_item['size'], 'created_at' => $db_item['created_at'],
                'updated_at' => $db_item['updated_at'], 'explaination' => '数据库已收录地图，但地图文件不在服务器上'
            ];
            $result = insert_request($map_request);
            if (!$result['success']) return array_error('插入数据库失败' . $result['message']);
            $map_request['id'] = $result['data'];
            $result = bind_request_user($map_request['id'], $user_id);
            if (!$result['success']) return array_error('请求添加成功但绑定用户失败' . $result['message']);
            return array_success($map_request);
    }
}

function delete_request_handler(bool $is_admin, int $user_id, int $request_id): array
{
    try {
        if ($is_admin) {
            delete_requests_by_request_id($request_id);
            delete_request($request_id);
        } else {
            delete_request_user($request_id, $user_id);
            $result = count_users_by_request($request_id);
            if ($result['success'] && (int)$result['data'] == 0) {
                delete_request($request_id);
            }
        }
    } catch (PDOException $e) { return array_error($e->getMessage()); }
    return array_success();
}

function approve_request(int $request_id): array
{
    $result = find_request_by_id($request_id);
    if (!$result['success']) return array_error($result['message']);
    $request = $result['data'];
    if (!$request) return array_error("未找到该地图申请。");
    if ($request['status'] !== 'pending') return array_error("当前地图申请非审核态");

    if (!$request['in_maps']) {
        $map = [
            'steam_id' => $request['steam_id'], 'link' => $request['link'], 'status' => 'abandon',
            'title' => $request['title'], 'version' => $request['version'], 'description' => $request['description'],
            'disk_safe' => $request['disk_safe'], 'downlink' => $request['downlink'],
            'size' => $request['size'], 'preview_url' => $request['preview_url'], 'is_map' => $request['is_map'],
            'subscriptions' => $request['subscriptions'] ?? 0,
        ];
        $result = insert_map($map);
        if (!$result['success']) return array_error('插入地图失败:' . $result['message']);
        $map_id = $result['data'];
    } else {
        $result = find_map_id_by_steam_id($request['steam_id']);
        if (!$result['success'] || !$result['data']) return array_error("更新地图信息失败:地图库中没有{$request['steam_id']}");
        $map_id = (int)$result['data'];
        $result = build_map_request($request['steam_id']);
        if (!$result['success']) return array_error('更新地图信息失败:无法通过api更新信息' . $result['message']);
        $map_info = $result['data'];
        $map_info['id'] = $map_id;
        $result = update_map($map_id, $map_info);
        if (!$result['success']) return array_error('插入地图失败:更新地图表时异常 ' . $result['message']);
    }

    $result = find_map_by_id($map_id);
    if (!$result['success']) return array_error($result['message']);
    $down_info = $result['data'];
    if (!$down_info) return array_error('找不到地图记录');
    if ($down_info['status'] !== 'abandon') return array_error('该地图已在服务器，拒绝批准');
    $size_bytes = intval(preg_replace('/[^0-9.]/', '', $down_info['size']));
    if (!check_disk_capacity($size_bytes)) return array_error("服务器剩余空间不足");
    if ($down_info['disk_safe'] === '') {
        update_map_disk_safe($map_id, $down_info['steam_id']);
        $down_info['disk_safe'] = $down_info['steam_id'];
    }

    $result = task_exists_duplicate($map_id, 'download');
    if ($result['success'] && $result['data']) return array_error('已有相同任务');
    $result = insert_task([
        'type' => 'download', 'map_id' => $map_id,
        'src' => $down_info['downlink'], 'dst' => MAP_DIR . $down_info['disk_safe'] . '.vpk', 'disk_safe' => $down_info['disk_safe'],
    ]);
    if (!$result['success']) return array_error($result['message']);

    update_request_status($request_id, 'approved');

    $result = get_user_ids_by_request($request_id);
    broadcast_messages($result['data'], '地图已批准并进入下载队列', $request['title']);
    return array_success('地图已批准并进入下载队列');
}

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
		$result = add_request($user_id, $steam_id);
		if ($result['success']) json_success($result['data']);
		json_error($result['message']);
		exit;
	case 'delete':
		$request_id = get_GET('request_id', 1, null);
		if (!$request_id) json_error('非法request_id');
		$result = delete_request_handler($is_admin, $user_id, $request_id);
		if (!$result['success']) json_error($result['message']);
		json_success($result['data']);
		exit;
	case 'list':
		$limit    = get_GET('limit', 1, 10);
		$offset   = get_GET('offset', 1, 0);
		$order_by = get_GET('order_by', 0, 'id');
		$order    = get_GET('order', 0, 'ASC');
		if ($is_admin) {
			$result = list_requests(['limit' => $limit, 'offset' => $offset, 'order_by' => $order_by, 'order' => $order]);
		} else {
			$result = list_requests_by_user($user_id, ['limit' => $limit, 'offset' => $offset, 'order_by' => $order_by, 'order' => $order]);
		}
		if (!$result['success']) json_error($result['message']);
		json_success($result['data']);
		exit;
	case 'count':
		$result = count_requests();
		if (!$result['success']) json_error($result['message']);
		json_success($result['data']);
		exit;
	case 'approve':
		if (!$is_admin) json_error('权限不足');
		$result = post_ids();
		if (!$result['success']) json_error($result['message']);
		$success = 0; $fail = 0; $msg = '';
		foreach ($result['data'] as $request_id) {
			$r = approve_request(intval($request_id));
			if ($r['success']) { $success++; }
			else { $fail++; error_log($r['message']); $msg .= $r['message']; }
		}
		json_success("成功批准 $success 个，失败 $fail 个 错误信息：$msg");
		exit;
	default:
		json_error('未知操作');
}

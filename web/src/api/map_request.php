<?php
include_once 'tools.php';
header('Content-Type: application/json');
//检查登录
if (!check_login()) json_error('请先登录。');
//设置报错日志
ini_set('log_errors', 1);
ini_set('error_log', '../logs/map_request_error.log');
//包含工具函数
include_once 'map_request_tools.php';

//POST获取json_encode(['ids'=>[1,2,3]]),逐个检查
function post_ids()
{
	if (!$_SERVER['REQUEST_METHOD'] === 'POST') return array_error('请求方法错误');
	$data = json_decode(file_get_contents('php://input'), true);
	$ids = $data['ids'] ?? [];
	if (!is_array($ids) || empty($ids)) {
		return array_error('非法ID');
	}
	foreach ($ids as $id) {
		if (!is_numeric($id)) return array_error('非法ID');
	}
	return array_success($ids);
}
/**
 * 处理添加请求
 * fetch_steam_item_by_api
 * fetch_db_item
 * fetch_map_request
 * insert_map_request
 * bind_user_to_request
 * 
 * @param PDO $pdo
 * @param int $user_id
 * @param string $steam_id
 * @return array ['success'=>bool,'data'=>array,'message'=>string]
 * @throws Exception
 */
function add_request($pdo, $user_id, $steam_id)
{
	if (!$steam_id) return array_error('非法steam_id');
	//检查数据库中是否已有
	$db_item = fetch_db_item($pdo, $steam_id);
	if (!$db_item) { //map_requests无pending记录且maps无任何记录
		//1.构造完整map_request
		$result = build_map_request($steam_id);
		if (!$result['success']) return array_error('构造请求失败' . $result['message']);
		$map_request = $result['data'];
		//2.map_request插入数据库
		delete_all_request($pdo, $steam_id); //删除同名过时申请
		$result = insert_map_request($pdo, $map_request);
		if (!$result['success']) return array_error('插入数据库失败' . $result['message']);
		//插入后拿maprequest_id
		$map_request['id'] = $result['data'];
		//绑定用户
		$result = bind_user_to_request($pdo, $map_request['id'], $user_id);
		if (!$result['success']) return array_error('绑定用户失败' . $result['message']);
		//3.返回请求信息
		//3.1剔除description因为太大而且不需要
		unset($map_request['description']);
		return array_success($map_request);
	} else switch ($db_item['status']) { //数据库中有，分maps或map_request表两种情况
		case 'pending': //1.map_requests已有相同地图申请审核中，此时db_item就是map_request
			//在对应申请直接绑定用户，
			$result = bind_user_to_request($pdo, $db_item['id'], $user_id);
			if (!$result['success']) return array_error('绑定用户失败' . $result['message']); //已在数据库设置unique(user,request)，所以不会重复绑定
			//返回申请信息
			$result = fetch_map_request($pdo, $db_item['id']);
			if (!$result['success']) return array_error($result['message']);
			$map_request = $result['data'];
			unset($map_request['description']);
			return array_success($map_request);
			//2. m_r无相同审核中请求但maps表有
		case 'updating': //2.1 maps表处于活动状态（在服务器上）
			return array_error("{$db_item['title']}正在更新！");
		case 'active': //已经有了还申请，拒绝
			return array_error("{$db_item['title']}已被收录!");
		case 'abandon': //2.2 maps表有但遗弃（不在服务器上），允许添加新申请
			//构造简略map_request，仅供前端显示
			//其他由insert_map_request自动置空,批准时识别in_maps然后从map表获取下载信息
			//status,in_maps,map_id,title,size,created_at,updated_at,explaination
			$map_request['steam_id'] = $steam_id;
			$map_request['status'] = 'pending'; //abandon->pending
			$map_request['in_maps'] = true;
			$map_request['map_id'] = $db_item['id'];
			$map_request['title'] = $db_item['title'];
			$map_request['size'] = $db_item['size'];
			$map_request['created_at'] = $db_item['created_at'];
			$map_request['updated_at'] = $db_item['updated_at'];
			$map_request['explaination'] = '数据库已收录地图，但地图文件不在服务器上';
			//插入map_request数据库
			$result = insert_map_request($pdo, $map_request);
			if (!$result['success']) return array_error('插入数据库失败' . $result['message']);
			//插入后确定id
			$map_request['id'] = $result['data'];
			//绑定用户
			$result = bind_user_to_request($pdo, $map_request['id'], $user_id);
			if (!$result['success']) return array_error('请求添加成功但绑定用户失败' . $result['message']);
			return array_success($map_request);
	}
}

include_once 'downloader.php';
/**
 * 批准逻辑,根据申请从maps表获取下载信息，添加下载任务
 */
function approve_request($pdo, $request_id)
{
	// 1.查询对应map_request 请求
	$stmt = $pdo->prepare("SELECT * FROM map_requests WHERE id = ?");
	$result = exec_stmt($stmt, $request_id);
	if (!$result['success']) return array_error($result['message']);
	$request = $result['data']->fetch(PDO::FETCH_ASSOC);
	// 2.确保请求存在且为审核态
	if (!$request) {
		return array_error("未找到该地图申请。");
	}
	if ($request['status'] !== 'pending') {
		return array_error("当前地图申请非审核态，");
	}

	// 获取maps表记录id
	if (!$request['in_maps']) {//没有，则添加map
		//申请中应有完整信息
		//steam_id,link,status, title,version,description,disk_safe,downlink,size,preview_url, is_map
		$map['steam_id'] = $request['steam_id'];
		$map['link'] = $request['link'];
		$map['status'] = 'abandon'; //正在批准申请，但文件尚未下载
		$map['title'] = $request['title'];
		$map['version'] = $request['version'];
		$map['description'] = $request['description'];
		$map['disk_safe'] = $request['disk_safe'];
		$map['downlink'] = $request['downlink'];
		$map['size'] = $request['size'];
		$map['preview_url'] = $request['preview_url'];
		$map['is_map'] = $request['is_map'];
		$result =  insert_map($pdo, $map);
		if (!$result['success']) return array_error('插入地图失败:' . $result['message']);
		$map_id = $result['data'];
	} else {//有，则根据id更新map信息
		//根据steam_id查map_id
		$stmt = $pdo->prepare("
			SELECT id
			FROM maps
			WHERE steam_id = ?
	    ");
		$stmt->execute([$request['steam_id']]);
		$result = $stmt->fetch(PDO::FETCH_ASSOC);
		if(!$result) return array_error("更新地图信息失败:地图库中没有{$request['steam_id']}");
		$map_id = $result['id'];
		//根据steam_id和api获取最新地图数据信息
		$result = build_map_request($request['steam_id']);
		if(!$result) return array_error('更新地图信息失败:无法通过api更新信息' . $result['message']);
		$map_info = $result['data'];
		//补充地图id信息
		$map_info['id'] = $map_id;
		$result = update_map_info($pdo,$map_info);
		if(!$result['success']) return array_error('插入地图失败:更新地图表时异常 ' . $result['message']);
	}
	// 3.根据map_id获取下载信息,保证下载信息是从maps表中获取
	$stmt = $pdo->prepare("SELECT status,disk_safe,downlink,size FROM maps WHERE id = ?");
	$result = exec_stmt($stmt, $map_id);
	if (!$result['success']) return array_error($result['message']);
	$down_info = $result['data']->fetch(PDO::FETCH_ASSOC);
	// 4.1检查地图状态
	if ($down_info['status'] !== 'abandon') return array_error('该地图已在服务器，拒绝批准');
	// 4.2检查磁盘空间
	$size_bytes = intval(preg_replace('/[^0-9.]/', '', $down_info['size']));
	if (!check_disk_capacity($size_bytes))  return array_error("服务器剩余空间不足");
	// 4.3检查下载名称是否为空？
	if($down_info['disk_safe'] === ''){//为空，使用steamid保证不重名
		$stmt = $pdo->prepare("update maps set disk_safe = ? where id = ?");
		$result = exec_stmt($stmt, $down_info['steam_id'], $map_id);
		if(!$result['success']) return array_error($result['message']);
		$down_info['disk_safe'] = $down_info['steam_id'];
	}
	// 6.添加下载任务
	$result = add_download_task($pdo, $down_info['downlink'], $down_info['disk_safe'], $map_id);
	if (!$result['success']) return array_error($result['message']);

	// 7.设置申请状态为 approved
	$stmt = $pdo->prepare("UPDATE map_requests SET status = 'approved' WHERE id = ?");
	$result = exec_stmt($stmt, $request_id);
	if (!$result['success']) return array_error($result['message']);
	// 8.向关联用户通知
	$result = fetch_users_by_request($pdo, $request_id);
	$user_ids = $result['data'];
	broadcast_message($user_ids, '地图已批准并进入下载队列', $request['title']);
	return array_success('地图已批准并进入下载队列');
}
//删除逻辑
function delete_all_request($pdo, $steam_id){
	try {
		$stmt = $pdo->prepare("DELETE FROM map_requests WHERE steam_id = ?");
		$stmt->execute([$steam_id]);
	} catch (PDOException $e) {
		return array_error($e->getMessage());
	}
	return array_success();
}
function delete_request($pdo, $is_admin, $user_id, $request_id)
{
	try { //尝试数据库操作
		//删除绑定关系
		if ($is_admin) {
			$stmt = $pdo->prepare("DELETE FROM map_requests WHERE id = ?");
			$stmt->execute([$request_id]);
		} else {
			$stmt = $pdo->prepare("DELETE FROM map_request_users WHERE request_id = ? AND user_id = ?");
			$stmt->execute([$request_id, $user_id]);
		}
		$stmt = $pdo->prepare("SELECT COUNT(*) FROM map_request_users WHERE request_id = ?");
		$stmt->execute([$request_id]);
		//如果删除后无绑定关系，则删除对应申请
		if ($stmt->fetch() == 0) {
			$stmt = $pdo->prepare("DELETE FROM map_requests WHERE id = ?");
			$stmt->execute([$request_id]);
		}
	} catch (PDOException $e) { //捕获数据库操作异常
		return array_error($e->getMessage());
	}
	return array_success();
}
/**
 * 计算总数
 */
function count_request($pdo)
{
	$stmt = $pdo->prepare("SELECT COUNT(*) FROM map_requests");
	$result = exec_stmt($stmt);
	if (!$result['success']) return array_error($result['message']);
	$count = $result['data']->fetch(PDO::FETCH_NUM)[0];
	return json_success($count);
}
// 列出逻辑
function list_request($pdo, $is_admin, $user_id, $limit, $offset, $order_by, $order)
{
	//参数验证
	$allowed_order = ['id', 'title', 'size', 'status']; // 允许的排序字段
	if (!in_array($order_by, $allowed_order)) {
		$order_by = 'id';
	}
	$allowed_order = ['ASC', 'DESC'];  // 允许的排序方式
	if (!in_array($order, $allowed_order)) {
		$order = 'DESC';
	}
	try { //日后添加请求人数统计
		if ($is_admin) { //管理员列出所有
			$stmt = $pdo->prepare("
				SELECT id,steam_id,title,status,created_at,updated_at,explaination,link,size 
				FROM map_requests
				ORDER BY $order_by $order
				LIMIT $limit OFFSET $offset
			");
			$stmt->execute();
		} else { //普通用户列出自己
			$stmt = $pdo->prepare("
				SELECT mr.id,mr.steam_id,mr.title,mr.status,mr.craeted_at,mr.updated_atmr.explaination,mr.link,mr.size 
				FROM map_requests mr
				JOIN map_request_users mru ON mr.id = mru.map_request_id
				WHERE mru.user_id = ?
				ORDER BY $order_by $order
				LIMIT $limit OFFSET $offset
			");
			$stmt->execute([$user_id]);
		}
	} catch (PDOException $e) {
		array_error($e->getMessage());
	}
	return array_success($stmt->fetchAll(PDO::FETCH_ASSOC));
}
//初始化变量
$pdo = conn_db();
$action = $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$is_admin = check_admin();
//判断请求类型
switch ($action) {
	case 'add':
		$error_msg = '非法steam_id';
		$steam_id = get_GET('steam_id', 1, $error_msg);
		if ($steam_id == $error_msg) json_error($error_msg);

		$result = add_request($pdo, $user_id, $steam_id);
		if ($result['success']) json_success($result['data']);
		json_error($result['message']);
		exit;
	case 'delete':
		$error_msg = '非法request_id';
		$request_id = get_GET('request_id', 1, $error_msg);
		if ($request_id == $error_msg) json_error($error_msg);
		$result = delete_request($pdo, $is_admin, $user_id, $request_id);
		if (!$result['success']) json_error($result['message']);
		json_success($result['data']);
		exit;
	case 'list':
		$limit = get_GET('limit', 1, 10);
		$offset = get_GET('offset', 1, 0);
		$order_by = get_GET('order_by', 'id');
		$order = get_GET('order', 'ASC');
		$result = list_request($pdo, $is_admin, $user_id, $limit, $offset, $order_by, $order);
		if (!$result['success']) json_error($result['message']);
		$list = $result['data'];
		json_success($list);
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
		$ids = $result['data'];
		$success = 0;
		$fail = 0;
		$msg = '';
		foreach ($ids as $request_id) {
			$result = approve_request($pdo, intval($request_id));
			if ($result['success']) {
				$success++;
			} else {
				$fail++;
				error_log($result['message']);
				$msg .= $result['message'];
			}
		}
		json_success("成功批准 $success 个，失败 $fail 个 错误信息：$msg");
		exit;
	default:
		json_error('未知操作');
}

<?php
include_once __DIR__ . '/../config.php';
include_once LIB_DIR . 'core.php';
include_once LIB_DIR . 'auth.php';
include_once LIB_DIR . 'db.php';
header('Content-Type: application/json');

// 认证：内部服务调用（task-daemon 每日更新）可通过 token 绕过登录
$sidcar_token = getenv('SIDECAR_TOKEN') ?: '';
$api_token    = $_GET['token'] ?? '';
$is_internal  = ($sidcar_token !== '' && hash_equals($sidcar_token, $api_token));

if (!$is_internal) {
    if (!check_login()) json_error('请先登录。');
    if (!check_admin()) json_error('权限不足。');
}
//设置报错日志（按日轮转）
ini_set('log_errors', 1);
ini_set('error_log', daily_log_path(LOG_DIR . 'map_manage_error.log'));

//POST获取json_encode(['ids'=>[1,2,3]]),逐个检查
function post_ids(){
	if (!$_SERVER['REQUEST_METHOD'] === 'POST') return array_error('请求方法错误');
	$data = json_decode(file_get_contents('php://input'), true);
	$ids = $data['ids'] ?? [];
	if (!is_array($ids) || empty($ids)) {
		return array_error('非法ID');
	}
	foreach($ids as $id){
		if (!is_numeric($id)) return array_error('非法ID');
	}
	return array_success($ids);
}
// 列出逻辑
function list_map($pdo,$limit=10,$offset=0,$order_by='id',$order='DESC'){
	
  $allowed_fields = ['id', 'title', 'size', 'steam_id', 'status'];
  if (!in_array($order_by, $allowed_fields)) {
      $order_by = 'id';
  }
  $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
  $sql = "
      SELECT id, title, size, steam_id, link, version, status
      FROM maps
      ORDER BY $order_by $order, title $order
      LIMIT $limit OFFSET $offset
  ";
  try{
			 $stmt = $pdo->query($sql);
	} catch (PDOException $e) {
		array_error($e->getMessage());
	}
	return array_success($stmt->fetchAll(PDO::FETCH_ASSOC));
}
/**
 * 查disk_safe删除maps后再删除文件(MAP_DIR/$disk_safe.vpk)
 */
function uninstall_map($pdo,$id){
  //查图状态和文件名
  $stmt = $pdo->prepare("SELECT disk_safe,status FROM maps WHERE id = ?");
  $result = exec_stmt($stmt, $id);
  if(!$result['success']) return array_error($result['message']);
  $stmt = $result['data'];
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $status = $row['status'];
  if($status=='updating') return array_error("地图正在更新，请稍后再试");
  $disk_safe = $row['disk_safe'];
  if(!$disk_safe) return array_error('记录中没有图名。');
  // 设置记录状态
  $stmt = $pdo->prepare("UPDATE maps SET status=? WHERE id = ?");
  $result = exec_stmt($stmt, 'abandon', $id);
  if(!$result['success']) return array_error($result['message']);
  //删除地图文件
  $file_path = MAP_DIR . $disk_safe . '.vpk';
  if (!file_exists($file_path)) return array_success("$disk_safe 删除成功。");
  if (!is_writable($file_path)||!is_writable(MAP_DIR)) return array_error("没有删除权限，请检查uninstall_map");
  unlink($file_path);
  return array_success("$disk_safe 删除成功。");
}
/**
 * 删除maps表中记录
 * @param PDO $pdo
 * @param int $id
 * @return array
 */
function delete_map($pdo,$id){
  // 1.卸载（删文件）
  $result = uninstall_map($pdo,$id);
  if(!$result['success']) return array_error($result['message']);
  // 3.删除maps记录
  $stmt = $pdo->prepare("DELETE FROM maps WHERE id = ?");
  $result = exec_stmt($stmt, $id);
  if(!$result['success']) return array_error($result['message']);
  return array_success("删除成功。");
}
/**
 * 对单张地图应用已获取的 Steam 信息（版本比较 → 更新记录 → 下载任务）
 *
 * 不负责获取 Steam 信息，只做本地处理。
 */
function apply_map_update($pdo, array $row, array $steam_info, bool $install): array {
  $id        = $row['id'];
  $version   = $row['version'] ?? 0;
  $title     = $row['title'];
  $needs_file = $install && ($version < $steam_info['version']);

  // 始终更新元数据（preview_url / subscriptions 等可能已变化）
  $stmt = $pdo->prepare("UPDATE maps SET version=?,downlink=?,size=?,title=?,preview_url=?,description=?,subscriptions=? WHERE id = ?");
  $result = exec_stmt($stmt,
    $steam_info['version'],
    $steam_info['downlink'],
    $steam_info['size'],
    $steam_info['title'],
    $steam_info['preview_url'],
    $steam_info['description'],
    $steam_info['subscriptions'],
    $id
  );
  if(!$result['success']) return array_error('更新数据库失败'.$result['message']);

  // 仅版本落后时重新下载地图文件
  if ($needs_file){
    $result = uninstall_map($pdo,$id);
    if(!$result['success']) return array_error('更新前删除地图文件失败'.$result['message']);
    include_once LIB_DIR . 'download.php';
    $disk_safe = $row['disk_safe'] ?: $row['steam_id'];
    $result = add_download_task($pdo, $steam_info['downlink'], $disk_safe, $id);
    if(!$result['success']) return array_error('添加下载任务失败'.$result['message']);
    $stmt = $pdo->prepare("UPDATE maps SET status='updating' WHERE id = ?");
    $result = exec_stmt($stmt,$id);
    if(!$result['success']) return array_error('更新数据库失败'.$result['message']);
    return array_success([
      'id'      => $id,
      'size'    => $steam_info['size'],
      'version' => $steam_info['version'],
      'status'  => 'updating'
    ]);
  }

  // 元数据已刷新，文件无需更新
  return array_success([
    'id'        => $id,
    'title'     => $title,
    'refreshed' => true
  ]);
}

/**
 * 批量更新地图（内部并行拉取 Steam 信息）
 *
 * @param array $map_rows DB 查询结果，每行需含 id, steam_id, status, title, disk_safe, version
 * @return array ['updated'=>int, 'failed'=>int, 'total'=>int, 'message'=>string]
 */
function update_maps($pdo, array $map_rows): array {
  if (empty($map_rows)) {
    return ['updated' => 0, 'failed' => 0, 'total' => 0, 'message' => '无待更新地图'];
  }

  include_once LIB_DIR . 'steam.php';
  $steam_ids  = array_column($map_rows, 'steam_id');
  $steam_infos = fetch_steam_items_batch($steam_ids);

  $updated = $refreshed = $fail_count = 0;
  foreach ($map_rows as $row) {
    $steam_info = $steam_infos[(string)$row['steam_id']] ?? false;
    if (!$steam_info) {
      $fail_count++;
      continue;
    }
    $install = ($row['status'] === 'active');
    $result  = apply_map_update($pdo, $row, $steam_info, $install);
    if (!$result['success']) {
      $fail_count++;
    } elseif (!empty($result['data']['refreshed'])) {
      $refreshed++;
    } else {
      $updated++;
    }
  }
  $msg = "检查完成：{$updated} 个已更新" . ($updated > 0 ? "（含文件下载）" : "") . "，{$refreshed} 个已刷新（仅元数据）";
  if ($fail_count > 0) $msg .= "，{$fail_count} 个失败";
  $msg .= "，共 " . count($map_rows) . " 个地图";
  return [
    'message'   => $msg,
    'updated'   => $updated,
    'refreshed' => $refreshed,
    'failed'    => $fail_count,
    'total'     => count($map_rows),
  ];
}
function count_map($pdo){
  $stmt = $pdo->query("SELECT COUNT(*) FROM maps");
  $row = $stmt->fetch(PDO::FETCH_NUM);
  return $row[0];
}
//初始化变量
$pdo = conn_db();
$action = $_GET['action'] ?? '';

//判断请求类型
switch($action){
  case 'list' :
    $limit = get_GET('limit',1,10);
    $offset = get_GET('offset',1,0);
    $order_by = get_GET('order_by',0,'id');
    $order = get_GET('order',0,'DESC');

    $result = list_map($pdo,$limit,$offset,$order_by,$order);
    if (!$result['success']) json_error($result['message']);
    json_success($result['data']);
  exit;
  case 'uninstall' :
   //拿地图id
    $result = post_ids();
    if (!$result['success']) json_error($result['message']);
    $ids = $result['data'];
    //循环删除
    $msg = '';
    foreach($ids as $id){
      $result = uninstall_map($pdo,$id);
      if (!$result['success']) $msg.=$result['message'].'\n';
    }
    json_success($msg);
  exit;
  case 'delete':
   //拿地图id
    $result = post_ids();
    if (!$result['success']) json_error($result['message']);
    $ids = $result['data'];
    //循环删除
    $msg = '';
    foreach($ids as $id){
      $result = delete_map($pdo,$id);
      if (!$result['success']) $msg.=$result['message'].'\n';
    }
    json_success($msg);
  exit;
  case 'update':
    //拿地图id
    $result = post_ids();
    if (!$result['success']) json_error('获取ids失败'.$result['message']);
    $ids = $result['data'];

    // 查出行数据，交给 update_maps 统一处理（内部并行拉取 Steam 信息）
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, steam_id, status, title, disk_safe, version FROM maps WHERE id IN ($in) AND status != 'updating'");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summary = update_maps($pdo, $rows);
    json_success($summary);
  exit;
  case 'update_all':
    // 查所有非 updating 地图，统一交给 update_maps 处理
    $stmt = $pdo->prepare("SELECT id, steam_id, status, title, disk_safe, version FROM maps WHERE status != 'updating' ");
    $result = exec_stmt($stmt);
    if(!$result['success']) json_error('查询数据库失败'.$result['message']);
    $rows = $result['data']->fetchAll(PDO::FETCH_ASSOC);

    $summary = update_maps($pdo, $rows);
    return json_success($summary);
  exit;
  case 'cos_sync':
    // 写入触发文件，由 task-daemon 轮询执行（daemon 有 addons 卷挂载）
    if (getenv('COS_SECRET_ID') === '' || getenv('COS_SECRET_KEY') === '' || getenv('COS_BUCKET') === '') {
        json_error('COS 未配置，请检查 COS_SECRET_ID / COS_SECRET_KEY / COS_BUCKET 环境变量');
    }

    $trigger_file = LOG_DIR . '.cos_sync';
    if (@file_put_contents($trigger_file, date('c')) === false) {
        json_error('无法写入触发文件，请检查 LOG_DIR 权限');
    }

    json_success([
      'message' => '已加入同步队列，daemon 将在下次轮询时执行（最长等待 ' . ($_ENV['DAEMON_INTERVAL'] ?? 5) . ' 秒）',
    ]);
  exit;
  case 'count':
    $result = count_map($pdo);
    json_success($result);
  exit;
  default:
    json_error('action参数错误');
}
?>
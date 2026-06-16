<?php
include_once 'tools.php';
header('Content-Type: application/json');
//检查登录与管理员权限
if (!check_login()) json_error('请先登录。');
$is_admin = check_admin();
if(!$is_admin) json_error('权限不足。');
//设置报错日志
ini_set('log_errors', 1);
ini_set('error_log', '../logs/map_manage_error.log');
//最大执行时间10min
// set_time_limit(600);
// ini_set('max_execution_time', 600);
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
 * 更新地图
 * 从api获取地图信息，比较版本，新版则先更新记录再添加下载任务
 */
function update_map($pdo, $id, $install = true){
  if(!$id) return array_error('id错误');
  if(!alive_db($pdo)) return array_error('数据库连接失败');
  //查数据库中地图版本状态
  $stmt = $pdo->prepare("SELECT title,disk_safe,steam_id,version,status FROM maps WHERE id = ?");
  $result = exec_stmt($stmt, $id);
  if(!$result['success']) return array_error('没找到该地图'.$result['message']);
  $row = $result['data']->fetch(PDO::FETCH_ASSOC);
  //排除更新态
  $status = $row['status'];
  if($status=='updating') return array_error("地图正在更新，请稍后再试");
  //获取地图信息
  $version = $row['version'] ?? 0;
  $title = $row['title'];
  $steam_id = $row['steam_id'];
  if( !$steam_id) return array_error("未找到数据库中$title 的版本信息。");
  //查steam中地图版本
  include_once API_DIR.'map_request_tools.php';
  $steam_info = fetch_steam_item_by_api($steam_id);
  if(!$steam_info) return array_error('未能通过steamworshop api查到steam中的地图信息');
  //检查版本若地图active+本地版本不小于steam的，则记录已经为最新
  if($version >= $steam_info['version'] && $status == 'active') return array_error("$title 版本已为最新。");
  //更新maps表
  $stmt = $pdo->prepare("UPDATE maps SET version=?,downlink=?,size=?,disk_safe=?,title=?,preview_url=?,description=?,subscriptions=? WHERE id = ?");
  $result = exec_stmt($stmt,
    $steam_info['version'],
    $steam_info['downlink'],
    $steam_info['size'],
    $steam_info['disk_safe'],
    $steam_info['title'],
    $steam_info['preview_url'],
    $steam_info['description'],
    $steam_info['subscriptions'],
    $id
  );
  if(!$result['success']) return array_error('更新数据库失败'.$result['message']);
  //更新文件
  if ($install){
    //先删
    $result = uninstall_map($pdo,$id);
    if(!$result['success']) return array_error('更新前删除地图文件失败'.$result['message']);
    //再添加下载任务
    include_once API_DIR.'downloader.php';
    $result = add_download_task($pdo,$steam_info['downlink'],$steam_info['disk_safe'],$id);
    if(!$result['success']) return array_error('添加下载任务失败'.$result['message']);
    //添加下载任务成功，更新状态为updating
    $stmt = $pdo->prepare("UPDATE maps SET status='updating' WHERE id = ?");
    $result = exec_stmt($stmt,$id);
    if(!$result['success']) return array_error('更新数据库失败'.$result['message']);
  }
  //返回成功
  return array_success([
    'id'=>$id,
    'size'=>$steam_info['size'],
    'version'=>$steam_info['version'],
    'status'=>'updating'
  ]);
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
    //准备更新
    $success_maps = [];
    $success_count = 0;
    $fail_count = 0;
    $total_count = count($ids);
    $msg = '';
    //循环更新
    foreach($ids as $id){
      $result = update_map($pdo,$id);
      if (!$result['success']) {
        $msg.=$result['message'].'<br>';
        $fail_count++;
        continue;
      }
      $success_count++;
      $success_maps[] = $result['data'];
    }
    json_success([
      'message'=>"成功更新$success_count 个，失败$fail_count 个，共$total_count 个$msg",
      'success_maps'=>$success_maps
    ]);
  exit;
  case 'update_all':
    //查 maps 表，不看 updating，只看 active 和 abandon
    //abandon 只刷新信息 active 额外更新文件
    $stmt = $pdo->prepare("SELECT id, status FROM maps WHERE status != 'updating' ");
    $result = exec_stmt($stmt);
    if(!$result['success']) return json_error('查询数据库失败'.$result['message']);
    $maps = $result['data']->fetchAll(PDO::FETCH_ASSOC);
    $summary = ['success'=>[], 'fail'=>''];
    foreach($maps as $map){
      $install = ($map['status'] === 'active');
      $result = update_map($pdo, $map['id'], $install);
      if($result['success']){
        $summary['success'] []= $result['data'];
        continue;
      }
      $summary['fail'] .= $result['message'] . "\n";
    }
    return json_success($summary);
  exit;
  case 'count':
    $result = count_map($pdo);
    json_success($result);
  exit;
  default:
    json_error('action参数错误');
}
?>
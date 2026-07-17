<?php
// 检查是否是通过命令行接口调用
if (php_sapi_name() != 'cli') {
    die('This script can only be run from the command line.');
}

include_once __DIR__ . '/../etc/config.php';
include_once __DIR__ . '/../lib/core.php';
include_once __DIR__ . '/../lib/db.php';
include_once __DIR__ . '/../lib/steam.php';

$log_file = LOG_DIR.'debug.log';// 手动日志文件
define('WEB_HOST', getenv('WEB_HOST') ?: 'nginx');
ini_set('log_errors', 1);//报错日志
ini_set('error_log', daily_log_path(LOG_DIR . 'debug.log'));//设置报错日志（按日轮转）

function update_maps_steam_id($pdo){
  $stmt = $pdo->prepare("select id,link from maps;");
      $update = $pdo->prepare("update maps set steam_id=? where id=?;");
      $stmt->execute();
      while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
        preg_match("/(?<=id=)\d+/",$row['link'],$matches);
        $update->execute([$matches[0],$row['id']]);
      }
}
function show_duplicate_maps_steam_id($pdo){
   $stmt = $pdo->prepare("select steam_id from maps group by steam_id having count(*)>1;");
      $stmt->execute();
      while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
        $stmt2 = $pdo->prepare("select title,link from maps where steam_id=?;");
        $stmt2->execute([$row['steam_id']]);
        $result = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        print_r($result);
      }
}
function filter_vpk($file_name){
  return pathinfo($file_name, PATHINFO_EXTENSION) === 'vpk';
}
/**
 * @return array ['success'=>true,'data'=>['disk_safe.vpk','disk_safe2.vpk'] ['success'=>false,'mesasge'=>'MAP_DIR不存在']
 */
function scan_maps(){
  //检查MAP_DIR目录是否存在
  if (!is_dir(MAP_DIR)) return array_error('MAP_DIR不存在');
  //MAP_DIR搜索现有地图名
  $files = scandir(MAP_DIR);
  $vpks = array_filter($files, 'filter_vpk');
  if(empty($vpks)) return array_error('MAP_DIR没有vpk文件');
  return array_success($vpks);
}
function disk_safe_to_map_info($pdo,$disk_safe){
    //disk_safe-->maps.id/steam_id
    $stmt = $pdo->prepare("select id,steam_id from maps where disk_safe=?;");
    $result = exec_stmt($stmt,$disk_safe);
    if(!$result['success']) return array_error("查询 $disk_safe 时出错{$result['message']}");
    
    $map = $result['data']->fetch(PDO::FETCH_ASSOC);
    if(!$map) return array_error("没有找到$disk_safe 对应数据");
    if(!$map['steam_id'])  return array_error("没有找到$disk_safe 对应steam_id");
    //steam_id->api->info
    $result = fetch_steam_item_by_api($map['steam_id']);
    if(!$result) return array_error("获取$disk_safe steam信息时出错");
    return array_success($result);
}
function select_map_info($pdo,$where,$val){
  $select_from = "select is_map,id,size,downlink,version,preview_url from maps";
  switch($where){
    case 'id':
      $stmt = $pdo->prepare($select_from." where id=?");
      break;
    case 'disk_safe':
      $stmt = $pdo->prepare($select_from." where disk_safe=?");
      break;
    case 'status':
      $stmt = $pdo->prepare($select_from." where status=?");
      break;
    default:
      return array_error("未知的where");
  }
  return exec_stmt($stmt,$val);
}
function check_map_info($map_info){
  $str_fields = [
    'downlink',
    'preview_url'
  ];
  $int_fields = [
    'version',
    'is_map',
    'size'
  ];
  foreach ($str_fields as $field) {
    if(!isset($map_info[$field])) return false;
    if($map_info[$field]=='') return false;
  }
  foreach ($int_fields as $field) {
    if(!isset($map_info[$field])  ) return false;
    if(!is_int($map_info[$field]) ) return false;
  }
  return true;
}
/**
 * 更新地图信息
 * @param array $map_info [status,size,downlink,disk_safe,preview_url,version,is_map]
 * @param int $map_id
 */
function debug_update_map_info($pdo,$map_info,$map_id){
  $stmt = $pdo->prepare("
  update maps 
  set status=?,size=?,downlink=?,disk_safe=?,title=?,preview_url=?,version=?,is_map=? where id=?
  ");
  return exec_stmt($stmt,
    $map_info['status'],
    intval($map_info['size']),
    $map_info['downlink'],
    $map_info['disk_safe'],
    $map_info['title'],
    $map_info['preview_url'],
    intval($map_info['version']),
    intval($map_info['is_map']),
    $map_id
  );
}
function ensure_maps_info($pdo){
  $log = '';
  //1.文件中已有的地图disk_safe
  $result = scan_maps();
  if(!$result['success']) return array_error($result['message']);
  $vpks = $result['data'];
  //遍历获取信息并填入('active',size,downlink)
  $now = microtime(true);//s
  $apilimit = 0.2;//s
  foreach ($vpks as $vpk) {
    $vpk_name = pathinfo($vpk,PATHINFO_FILENAME);
    $vpk_disk_safe = strtolower($vpk_name);
    //先看maps表是否存在disk_safe相同的map
    $result = select_map_info($pdo,'disk_safe',$vpk_disk_safe);
    if(!$result['success']) $log.="查询$vpk 信息失败{$result['message']}\n";
    
    $map = $result['data']->fetch(PDO::FETCH_ASSOC);
    if(!$map) continue;
    
    //如果maps表存在disk_safe相同的map,再看信息size,downlink是否齐全，齐全则只更新状态为active
    if(check_map_info($map)==true){
      $stmt = $pdo->prepare("update maps set status='active' where id=?");
      $result = exec_stmt($stmt,$map['id']);
      if(!$result['success']) $log.="更新$vpk 信息失败{$result['message']}\n";
      continue;
    }
    //信息不全，api补全
    //限制api访问频率
    $sleep_sec = microtime(true)-$now+$apilimit;//s
    usleep($sleep_sec*10^6);//s->us
    $now = microtime(true);
    //api获取信息
    $result = disk_safe_to_map_info($pdo,$vpk_disk_safe);
    if(!$result['success']) $log.="获取$vpk 信息失败 {$result['message']}\n";
    
    $map_info = $result['data'];
    //更新maps表
    $map_info['status'] = 'active';//$vpk存在说明状态为active
    $result = update_map_info($pdo,$map_info,$map['id']);
    if(!$result['success']) $log.="更新$vpk 信息失败{$result['message']}\n";
  }
  //2.看maps表abandon
  $result = select_map_info($pdo,'status','abandon');
  if(!$result['success']) $log.="查询maps表失败{$result['message']}\n";
  $maps = $result['data']->fetchAll(PDO::FETCH_ASSOC);
  if(empty($maps)){
    $log.="没有abandon状态地图\n";
    print($log);
    return;
  }
  //遍历maps获取信息并填入('active',size,downlink)
  $now = microtime(true);//s
  foreach ($maps as $map) {
    //先看map的disk_safe,size,downlink是否齐全，齐全则跳过(size要求纯整数以字节为单位)
    if(check_map_info($map)==true) continue;
    //信息不齐全的，api补全
    //限制api访问频率
    $sleep_sec = microtime(true)-$now+$apilimit;//s
    usleep($sleep_sec*10^6);//s->us
    $now = microtime(true);
    //api获取信息
    $result = fetch_steam_item_by_api($map['steam_id']);
    if(!$result) {
      $log.= "ensure_maps_info fetch获取map_id:{$map['id']} 的信息时出错";
      continue;
    }
    $map_info = $result;
    
    $map_info['status'] = 'abandon';//不在服务器上的地图，状态改为abandon
    $result = update_map_info($pdo,$map_info,$map['id']);
    if(!$result['success']) $log.="更新$map 信息失败{$result['message']}\n";
  }
  print($log);
  return;
}
function update_subscriptions($pdo){
  $select = $pdo->prepare("select steam_id from maps where steam_id is not null");
  $update = $pdo->prepare("update maps set subscriptions = :subscriptions  where steam_id = :steam_id");
  $select->execute();
  while($row = $select->fetch(PDO::FETCH_ASSOC)){
    $steam_id = $row['steam_id'];
    $result = fetch_steam_item_by_api($steam_id);
    if(!$result) continue;
    $update->execute([
      ':subscriptions' => $result['subscriptions'],
      ':steam_id' => $steam_id
    ]);
  }
}
include_once __DIR__ . '/../lib/ses.php';
//$pdo=conn_db();
//update_subscriptions($pdo);
function update_map_all($log_file){
  add_log($log_file, 1, '===Starting daily map update check===');
  // 调用 API 获取更新任务
  $api_url = "http://" . WEB_HOST . "/api/map_manage.php?action=update_all";
  
  $response = @file_get_contents($api_url);
  $error = error_get_last();
  
  if ($response === false) {
      $error_msg = $error['message'] ?? 'Unknown error';
      add_log($log_file, 2, "Daily update API call failed: {$error_msg}");
      add_log($log_file, 2, "Debug: URL = {$api_url}");
  } else {
    $result = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        add_log($log_file, 3, "Daily update API response parse error: " . json_last_error_msg());
    } elseif (isset($result['success']) && $result['success']) {
        // 成功：记录 data 内容
        $data = $result['data'] ?? 'empty';
        add_log($log_file, 1, "Daily update success: " . json_encode($data));
    } else {
        // 失败：记录 message 内容
        $msg = $result['message'] ?? 'Unknown error';
        add_log($log_file, 2, "Daily update failed: " . $msg);
    }
  }
}
//update_map_all($log_file);
?>
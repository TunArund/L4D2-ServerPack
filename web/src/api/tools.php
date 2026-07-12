<?php
// 路径常量 —— 优先从环境变量读取，Docker 容器内注入默认值
define('MAP_DIR',    getenv('MAP_DIR')    ?: '/var/www/addons/workshop/');
define('API_DIR',    getenv('API_DIR')    ?: '/var/www/html/api/');
define('LOG_DIR',    getenv('LOG_DIR')    ?: '/var/www/html/logs/');
define('SCRIPT_DIR', getenv('SCRIPT_DIR') ?: '/var/www/html/scripts/');
define('HTTP_PROXY', getenv('HTTP_PROXY') ?: '');
define('DB_HOST', getenv('DB_HOST')         ?: 'mysql');
define('DB_NAME', getenv('DB_DATABASE')     ?: 'steam');
define('DB_USER', getenv('DB_USER')         ?: 'steam');
define('DB_PASS', getenv('DB_PASSWORD')     ?: '');

/**
 * 检查宿主机磁盘剩余空间是否足够下载
 *
 * 容器内 exec('df | grep /dev/sd') 会因 overlay 文件系统返回空，
 * 故用 disk_free_space()，直接调用 statfs() 穿透容器层。
 */
function check_disk_capacity($size_bytes) {
    return disk_free_space('/var/www/html') > $size_bytes;
}

/**
 * 日志记录（自动按日轮转，目录格式: {base}/YYYY/MM/DD_{filename}）
 * @param string $log_file 日志文件基路径
 * @param string $msg 日志内容
 * @param int $level 日志级别 0为debug，1为info，2为warning，3为error
 */
function add_log($log_file, int $level=1, $msg=''){
  $timestamp = date('Y-m-d H:i:s');
  $level_str = ['DEBUG', 'INFO', 'WARNING', 'ERROR'][$level];
  $content = "[{$timestamp}] [{$level_str}] {$msg}";
  $daily = daily_log_path($log_file);
  file_put_contents($daily, $content . "\n", FILE_APPEND);
}

/**
 * 根据基路径生成按日轮转的日志文件路径
 *
 * 目录结构: {应用名}/{年}/{月}/{日}.log
 * 例如 /var/log/daemon.log → /var/log/daemon/2026/07/10.log
 *
 * @param string $base_path 日志基路径
 * @return string 轮转后的实际文件路径
 */
function daily_log_path(string $base_path): string {
    $dir  = dirname($base_path);
    $name = basename($base_path, '.log');           // 去掉 .log 后缀作目录名
    $date_dir = $dir . '/' . $name . '/' . date('Y') . '/' . date('m');
    if (!is_dir($date_dir)) {
        mkdir($date_dir, 0755, true);
    }
    return $date_dir . '/' . date('d') . '.log';
}
function check_login(){
  // 本地访问直接通过
  $allowed_ips = ['127.0.0.1', 'localhost', '::1'];
  if (in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
    return true;
  }
  // 远程访问检查session
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }
  if (isset($_SESSION['user_id'])) {
    return true;
  } else {
    return false;
  }
}
function check_admin(){
  // 本地访问直接通过
  $allowed_ips = ['127.0.0.1', 'localhost', '::1'];
  if (in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
    return true;
  }
  // 远程访问检查session
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }
  if (isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin') {
    return true;
  } else {
    return false;
  }
}
function conn_db(){
    // 数据库连接 —— 全部从环境变量读取，方便 CI/CD 配置
    // 不使用 static 缓存：断连后 safe_execute / ensure_db_alive 需要重新创建连接
    try {
        $pdo = new PDO(
          "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
          DB_USER,
          DB_PASS,
          [
            PDO::ATTR_TIMEOUT => 30,          // 设置超时时间30秒
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // 设置错误模式为异常
          ]
        );
    } catch (PDOException $e) {
        die("数据库连接失败：" . $e->getMessage());
        return False;
    }
    return $pdo;
}
/**
 * 检查数据库是否可用
 * @param PDO $pdo
 * @return bool
 */
function alive_db(PDO $pdo):bool{
  try{
    $stmt = $pdo->query("SELECT 1");
    return $stmt !== false;
  } catch (PDOException $e) {
    return false;
  }
}
/**
 * 保证pdo操作不会报错
 * @param PDOStatement $stmt
 * @param varparam $params $var1,$var2...
 * @return array ['success' => true, 'data' => $stmt] or ['success' => false, 'message' => $e->getMessage()]
 */
function exec_stmt($stmt,...$params){
  try{
    $stmt->execute($params);
  }catch(PDOException $e){
    return array_error($e->getMessage());
  }
  return array_success($stmt);
}
/**
 * 安全取得GET参数
 * @param string $key GET参数名
 * @param int $type 类型，0为字符串，1为整数
 * @param string|int $default 默认值
 * @return string|int|null GET参数值，若不存在则返回null
 */
function get_GET($key, $type = 0, $default = null)
{
  $val = null;
  switch ($type) {
    case 0:
      $val = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);
      break;
    case 1:
      $val = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
      break;
    default:
      return null;
  }
  if ($val == null) return $default;
  return $val;
}
/**
 * 安全取得POST参数
 * @param string $key POST参数名
 * @param int $type 类型，0为字符串，1为整数
 * @param string|int $default 默认值
 * @return string|int|null POST参数值，若不存在则返回null
 */
function get_POST($key, $type = 0, $default = null)
{
  $val = null;
  switch ($type) {
    case 0://str
      $val = filter_input(INPUT_POST, $key, FILTER_UNSAFE_RAW);
      break;
    case 1://int
      $val = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
      break;
    default:
      return null;
  }
  if ($val == null) return $default;
  return $val;
}
//json错误返回
function json_error($msg){
	echo json_encode(['success' => false, 'message' => $msg]);
	exit;
}
//json成功返回
function json_success($data = []){
	echo json_encode(['success' => true, 'data' => $data]);
	exit;
}
//array错误返回
function array_error($msg){
	return ['success' => false, 'message' => $msg];
}
//array成功返回
function array_success($data = []){
	return ['success' => true, 'data' => $data];
}
//字节数转自动单位str
function bytes_to_str($bytes) {
  $units = array('B', 'KB', 'MB', 'GB', 'TB');
  $unit = 0;
  while ($bytes >= 1024 && $unit < count($units) - 1) {
      $bytes /= 1024;
      $unit++;
  }
  return round($bytes, 2) . ' ' . $units[$unit];
}
//数字转K M B T
function num_to_str($number) {
    $units = ['', 'K', 'M', 'B', 'T'];
    $index = 0;
    while ($number >= 1000 && $index < count($units) - 1) {
        $number /= 1000;
        $index++;
    }
    return round($number, 2) . $units[$index];
}
function rate_limit($limit = 2, $window = 1) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $now = microtime(true);

    if (!isset($_SESSION['requests'])) {
        $_SESSION['requests'] = [];
    }

    // 清理过期请求
    $_SESSION['requests'] = array_filter($_SESSION['requests'], function($t) use ($now, $window) {
        return $t > $now - $window;
    });

    if (count($_SESSION['requests']) >= $limit) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Too Many Requests',
            'message' => "请求过于频繁，请稍后再试。",
            'retry_after' => $window
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION['requests'][] = $now;
}


function curl_proxy(string $url) : bool|string {
  // 使用 cURL 通过代理获取页面（代理地址从环境变量 HTTP_PROXY 读取，不设则不使用代理）
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', // 防止403
        CURLOPT_TIMEOUT => 10,
    ];
    if (HTTP_PROXY) {
        $opts[CURLOPT_PROXY] = HTTP_PROXY;
    }
    curl_setopt_array($ch, $opts);
    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // 获取失败或状态码非200
    if (!$html || $http_code !== 200) return false;
    return $html;
}
function broadcast_message($user_ids,$title,$message){
  $pdo = conn_db();
  try{
    foreach($user_ids as $user_id){
      $stmt = $pdo->prepare("INSERT INTO messages (user_id, title, message) VALUES (?, ?, ?)");
      $stmt->execute([$user_id, $title, $message]);
    }
  }catch(PDOException $e){
    return false;
  }
  $pdo = null;
  return true;
}


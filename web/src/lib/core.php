<?php
include_once __DIR__ . '/../config.php';

// ============================================================
// 核心库 — DB连接、日志、格式化、通用辅助
// ============================================================

function check_disk_capacity($size_bytes) {
    return disk_free_space('/var/www/html') > $size_bytes;
}

function add_log($log_file, int $level=1, $msg=''){
  $timestamp = date('Y-m-d H:i:s');
  $level_str = ['DEBUG', 'INFO', 'WARNING', 'ERROR'][$level];
  $content = "[{$timestamp}] [{$level_str}] {$msg}";
  $daily = daily_log_path($log_file);
  file_put_contents($daily, $content . "\n", FILE_APPEND);
}

function daily_log_path(string $base_path): string {
    $dir  = dirname($base_path);
    $name = basename($base_path, '.log');
    $date_dir = $dir . '/' . $name . '/' . date('Y') . '/' . date('m');
    if (!is_dir($date_dir)) {
        mkdir($date_dir, 0755, true);
    }
    return $date_dir . '/' . date('d') . '.log';
}

function conn_db(){
    try {
        $pdo = new PDO(
          "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
          DB_USER,
          DB_PASS,
          [ PDO::ATTR_TIMEOUT => 30, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
        );
    } catch (PDOException $e) {
        die("数据库连接失败：" . $e->getMessage());
    }
    return $pdo;
}

function get_GET($key, $type = 0, $default = null) {
  $val = null;
  switch ($type) {
    case 0: $val = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW); break;
    case 1: $val = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT); break;
    default: return null;
  }
  if ($val == null) return $default;
  return $val;
}

function get_POST($key, $type = 0, $default = null) {
  $val = null;
  switch ($type) {
    case 0: $val = filter_input(INPUT_POST, $key, FILTER_UNSAFE_RAW); break;
    case 1: $val = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT); break;
    default: return null;
  }
  if ($val == null) return $default;
  return $val;
}

function json_error($msg){
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}
function json_success($data = []){
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}
function array_error($msg){
    return ['success' => false, 'message' => $msg];
}
function array_success($data = []){
    return ['success' => true, 'data' => $data];
}

function bytes_to_str($bytes) {
  $units = array('B', 'KB', 'MB', 'GB', 'TB');
  $unit = 0;
  while ($bytes >= 1024 && $unit < count($units) - 1) { $bytes /= 1024; $unit++; }
  return round($bytes, 2) . ' ' . $units[$unit];
}

function num_to_str($number) {
    $units = ['', 'K', 'M', 'B', 'T'];
    $index = 0;
    while ($number >= 1000 && $index < count($units) - 1) { $number /= 1000; $index++; }
    return round($number, 2) . $units[$index];
}

function curl_proxy(string $url) : bool|string {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', CURLOPT_TIMEOUT => 10,
    ];
    if (HTTP_PROXY) { $opts[CURLOPT_PROXY] = HTTP_PROXY; }
    curl_setopt_array($ch, $opts);
    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
  }catch(PDOException $e){ return false; }
  $pdo = null;
  return true;
}

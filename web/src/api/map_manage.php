<?php
// config / core / auth 已由 bootstrap.php 自动加载
// Content-Type: application/json 已由 json_error/json_success 自动设置
include_once LIB_DIR . 'db.php';
include_once LIB_DIR . 'map.php';

// 认证：内部服务调用（task-daemon 每日更新）可通过 token 绕过登录
$sidcar_token = getenv('SIDECAR_TOKEN') ?: '';
$api_token    = $_GET['token'] ?? '';
$is_internal  = ($sidcar_token !== '' && hash_equals($sidcar_token, $api_token));

if (!$is_internal) {
    if (!check_login()) json_error('请先登录。');
    if (!check_admin()) json_error('权限不足。');
    if (!verify_csrf()) json_error('CSRF 验证失败，请刷新页面重试。');
}
//设置报错日志（按日轮转）
ini_set('log_errors', 1);
ini_set('error_log', daily_log_path(LOG_DIR . 'map_manage_error.log'));

$pdo = conn_db();
$action = $_GET['action'] ?? '';

switch($action){
  case 'list':
    $limit    = get_GET('limit', 1, 10);
    $offset   = get_GET('offset', 1, 0);
    $order_by = get_GET('order_by', 0, 'id');
    $order    = get_GET('order', 0, 'DESC');
    $result   = list_map($pdo, $limit, $offset, $order_by, $order);
    if (!$result['success']) json_error($result['message']);
    json_success($result['data']);
  exit;
  case 'uninstall':
    $result = post_ids();
    if (!$result['success']) json_error($result['message']);
    $msg = '';
    foreach ($result['data'] as $id) {
        $r = uninstall_map($pdo, $id);
        if (!$r['success']) $msg .= $r['message'] . '\n';
    }
    json_success($msg);
  exit;
  case 'delete':
    $result = post_ids();
    if (!$result['success']) json_error($result['message']);
    $msg = '';
    foreach ($result['data'] as $id) {
        $r = delete_map($pdo, $id);
        if (!$r['success']) $msg .= $r['message'] . '\n';
    }
    json_success($msg);
  exit;
  case 'update':
    $result = post_ids();
    if (!$result['success']) json_error('获取ids失败' . $result['message']);
    $ids = $result['data'];
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, steam_id, status, title, disk_safe, version FROM maps WHERE id IN ($in) AND status != 'updating'");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $summary = update_maps($pdo, $rows);
    json_success($summary);
  exit;
  case 'update_all':
    $stmt = $pdo->prepare("SELECT id, steam_id, status, title, disk_safe, version FROM maps WHERE status != 'updating'");
    $result = exec_stmt($stmt);
    if (!$result['success']) json_error('查询数据库失败' . $result['message']);
    $rows = $result['data']->fetchAll(PDO::FETCH_ASSOC);
    $summary = update_maps($pdo, $rows);
    json_success($summary);
  exit;
  case 'cos_sync':
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
    json_success(count_map($pdo));
  exit;
  default:
    json_error('action参数错误');
}
?>
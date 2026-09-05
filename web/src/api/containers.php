<?php
// config / core / auth 已由 bootstrap.php 自动加载
// ============================================================
// 容器管理代理 — 仅管理员可访问
// 服务端转发到 sidecar（内网），sidecar 不再直接暴露公网。
// 前端不再持有 SIDECAR_TOKEN，统一走 session（check_admin + CSRF）。
// ============================================================

if (!check_admin()) {
    json_error('权限不足。', 403);
}
if (!verify_csrf()) {
    json_error('CSRF 验证失败，请刷新页面重试。', 403);
}
// 鉴权 + CSRF 校验完成后释放会话锁，避免 sidecar 慢请求阻塞同 session 的并发请求
session_write_close();

$action = $_GET['action'] ?? 'list';
$token  = getenv('SIDECAR_TOKEN') ?: '';
$base   = 'http://sidecar:8080';

switch ($action) {
    case 'list':
        $url = $base . '/containers';
        $method = 'GET';
        break;
    case 'logs':
        $name = $_GET['name'] ?? '';
        $tail = max(1, min(200, (int)($_GET['tail'] ?? 50)));
        $url = $base . '/containers/' . rawurlencode($name) . '/logs?tail=' . $tail;
        $method = 'GET';
        break;
    case 'restart':
        $name = $_GET['name'] ?? '';
        $url = $base . '/containers/' . rawurlencode($name) . '/restart';
        $method = 'POST';
        break;
    default:
        json_error('未知操作');
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => ['X-Auth-Token: ' . $token],
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($body === false) {
    json_error('服务管理不可用：' . $err, 502);
}

http_response_code($code);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo $body;

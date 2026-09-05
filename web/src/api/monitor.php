<?php
// config / core / auth 已由 bootstrap.php 自动加载
// ============================================================
// 系统监控代理 — 仅登录用户可访问
// 服务端转发到 Glances REST API（compose 桥接网络 glances:61208）
// 原因：Glances 本身无鉴权，原 /monitor-api/ 直连已关闭，
//       这里用 session 登录态做门槛，避免公网任意读取宿主机指标。
// ============================================================

if (!check_login()) {
    json_error('请先登录。');
}
// 鉴权完成后立即释放会话锁，避免 Glances 慢请求阻塞同 session 的并发请求
session_write_close();

// type 白名单 → Glances API v4 路径（硬编码映射，防任意端点/SSRF）
$routes = [
    'cpu'     => '/api/4/cpu',
    'mem'     => '/api/4/mem',
    'fs'      => '/api/4/fs',
    'network' => '/api/4/network',
];
$type = $_GET['type'] ?? '';
if (!isset($routes[$type])) {
    json_error('未知监控类型');
}

$url = 'http://glances:61208' . $routes[$type];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 3,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($body === false) {
    json_error('监控服务不可用：' . $err);
}

http_response_code($code);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo $body;

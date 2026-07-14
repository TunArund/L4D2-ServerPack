<?php
// ============================================================
// 认证 / 权限 / 频率限制
// ============================================================

function check_login(){
  $allowed_ips = ['127.0.0.1', 'localhost', '::1'];
  if (in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
    return true;
  }
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
  $allowed_ips = ['127.0.0.1', 'localhost', '::1'];
  if (in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
    return true;
  }
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }
  if (isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin') {
    return true;
  } else {
    return false;
  }
}

function rate_limit($limit = 2, $window = 1) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $now = microtime(true);
    if (!isset($_SESSION['requests'])) {
        $_SESSION['requests'] = [];
    }
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

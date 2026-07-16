<?php
// ============================================================
// 认证 / 权限 / 频率限制
// ============================================================

function check_login(){
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
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }
  if (isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin') {
    return true;
  } else {
    return false;
  }
}

// ============================================================
// CSRF 保护
// ============================================================
function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_hidden_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf(): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD' || $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        return true;  // 只读请求无需 CSRF 保护
    }
    // 优先检查 POST 表单字段，其次检查 HTTP header（JSON API）
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    return true;
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

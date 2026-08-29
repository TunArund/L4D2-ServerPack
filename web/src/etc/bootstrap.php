<?php
// ============================================================
// 统一前置加载 — 由 nginx fastcgi_param auto_prepend_file 注入
// 每个通过 nginx → PHP-FPM 的请求自动执行，无需业务文件手动 include
// CLI 脚本（如 bin/task_daemon.php）不走此路径，独立引导
// ============================================================
require_once __DIR__ . '/config.php';
require_once LIB_DIR . 'core.php';
require_once LIB_DIR . 'db.php';
require_once LIB_DIR . 'auth.php';

// 数据访问层 — 按表分文件，纯函数
require_once TABLES_DIR . 'users.php';
require_once TABLES_DIR . 'messages.php';
require_once TABLES_DIR . 'comments.php';
require_once TABLES_DIR . 'emails.php';
require_once TABLES_DIR . 'maps.php';
require_once TABLES_DIR . 'tasks.php';
require_once TABLES_DIR . 'map_requests.php';
require_once TABLES_DIR . 'map_request_users.php';

if (session_status() === PHP_SESSION_NONE) {
    // 会话 Cookie 加固：仅 HTTPS 发送（Secure）、禁止 JS 读取（HttpOnly）、SameSite=Lax。
    // IP 明文访问时浏览器不携带会话，登录态不会在明文下泄露。
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

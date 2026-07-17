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
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

<?php
// ============================================================
// 全局路径与配置常量
// ============================================================
define('SRC_DIR', __DIR__ . '/');
define('LIB_DIR', SRC_DIR . 'lib/');
define('API_DIR', SRC_DIR . 'api/');
define('MAP_DIR', getenv('MAP_DIR') ?: '/var/www/addons/workshop/');
define('LOG_DIR', getenv('LOG_DIR') ?: SRC_DIR . 'logs/');
define('HTTP_PROXY', getenv('HTTP_PROXY') ?: '');
define('DB_HOST', getenv('DB_HOST') ?: 'mysql');
define('DB_NAME', getenv('DB_DATABASE') ?: 'steam');
define('DB_USER', getenv('DB_USER') ?: 'steam');
define('DB_PASS', getenv('DB_PASSWORD') ?: '');

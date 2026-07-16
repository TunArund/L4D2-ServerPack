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
// 品牌/部署特定常量（通过 .env 覆盖）
define('SERVER_CONNECT', getenv('SERVER_CONNECT') ?: '82.156.112.164:27015');
define('BRAND_EMAIL', getenv('BRAND_EMAIL') ?: 'tunarund@tunarund.top');
define('BRAND_DOMAIN', getenv('BRAND_DOMAIN') ?: 'tunarund.top');
define('BRAND_REPLY_EMAIL', getenv('BRAND_REPLY_EMAIL') ?: 'yaokun-handsome@qq.com');
define('BRAND_COMPANY', getenv('BRAND_COMPANY') ?: 'Tunarund GameLife');
define('BRAND_SITE', getenv('BRAND_SITE') ?: 'TunArund');


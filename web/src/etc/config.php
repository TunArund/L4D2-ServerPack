<?php
// ============================================================
// 全局路径与配置常量
// ============================================================
define('SRC_DIR', dirname(__DIR__) . '/');
define('ETC_DIR', __DIR__ . '/');
define('BIN_DIR', SRC_DIR . 'bin/');
define('LIB_DIR', SRC_DIR . 'lib/');
define('TABLES_DIR', SRC_DIR . 'tables/');
define('API_DIR', SRC_DIR . 'api/');
define('MAP_DIR', getenv('MAP_DIR') ?: '/var/www/addons/workshop/');
define('LOG_DIR', getenv('LOG_DIR') ?: SRC_DIR . 'logs/');
define('HTTP_PROXY', getenv('HTTP_PROXY') ?: '');
define('DB_HOST', getenv('DB_HOST') ?: 'mysql');
define('DB_NAME', getenv('DB_DATABASE') ?: 'steam');
define('DB_USER', getenv('DB_USER') ?: 'steam');
define('DB_PASS', getenv('DB_PASSWORD') ?: '');
// 品牌/部署特定常量（无内置默认值，必须通过 .env 注入）
// 对应项见 .env.example「Web 站点品牌」
define('SERVER_IP', getenv('SERVER_IP') ?: '');        // 游戏服公网 IP（仅 IP，不含端口）
define('SERVER_PORT', getenv('L4D2_PORT') ?: '27015'); // 游戏服对外端口
define('SERVER_ADDR', SERVER_IP !== '' ? SERVER_IP . ':' . SERVER_PORT : ''); // 一键连接/IP 直连完整地址
define('BRAND_EMAIL', getenv('BRAND_EMAIL') ?: '');
define('BRAND_DOMAIN', getenv('BRAND_DOMAIN') ?: '');
define('BRAND_REPLY_EMAIL', getenv('BRAND_REPLY_EMAIL') ?: '');
define('BRAND_COMPANY', getenv('BRAND_COMPANY') ?: '');
define('BRAND_SITE', getenv('BRAND_SITE') ?: '');
define('BRAND_STEAM_GROUP', getenv('BRAND_STEAM_GROUP') ?: '');
// 页脚备案：BRAND_ICP 为显示文本；BRAND_PSB 为纯数字编码（用于链接与显示拼装）
define('BRAND_ICP', getenv('BRAND_ICP') ?: '');
define('BRAND_PSB', getenv('BRAND_PSB') ?: '');

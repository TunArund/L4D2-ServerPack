#!/bin/bash
# auto_cos — COS 模块加载 + 签名验证

echo "[COS 模块]"

# 函数可加载
check "cos_configured() 可调用" 'docker compose exec -T task-daemon php -r "
include_once \"/var/www/html/api/tools.php\";
include_once \"/var/www/html/api/cos_client.php\";
exit(function_exists(\"cos_configured\") ? 0 : 1);
"'

check "cos_generate_auth() 可调用" 'docker compose exec -T task-daemon php -r "
include_once \"/var/www/html/api/tools.php\";
include_once \"/var/www/html/api/cos_client.php\";
exit(function_exists(\"cos_generate_auth\") ? 0 : 1);
"'

check "cos_head_object() 可调用" 'docker compose exec -T task-daemon php -r "
include_once \"/var/www/html/api/tools.php\";
include_once \"/var/www/html/api/cos_client.php\";
exit(function_exists(\"cos_head_object\") ? 0 : 1);
"'

check "cos_upload_file() 可调用" 'docker compose exec -T task-daemon php -r "
include_once \"/var/www/html/api/tools.php\";
include_once \"/var/www/html/api/cos_client.php\";
exit(function_exists(\"cos_upload_file\") ? 0 : 1);
"'

check "daily_log_path() 可调用" 'docker compose exec -T task-daemon php -r "
include_once \"/var/www/html/api/tools.php\";
exit(function_exists(\"daily_log_path\") ? 0 : 1);
"'

# 签名格式验证（仅在 COS 配置时有效）
echo ""
echo "[COS 签名格式]"
SIG_TEST=$(docker compose exec -T task-daemon php -r '
include_once "/var/www/html/api/tools.php";
include_once "/var/www/html/api/cos_client.php";
if (!cos_configured()) { echo "SKIP:not_configured"; exit(0); }
$host = cos_host();
$date = gmdate("D, d M Y H:i:s \G\M\T");
$auth = cos_generate_auth("HEAD", "/l4d2-maps/test.vpk", ["Date"=>$date,"Host"=>$host]);
echo $auth;
' 2>/dev/null)

if echo "$SIG_TEST" | grep -q 'SKIP'; then
    warn "COS 未配置，跳过签名验证"
elif echo "$SIG_TEST" | grep -q 'q-sign-algorithm=sha1'; then
    echo -e "  \033[0;32m✓\033[0m 签名包含 q-sign-algorithm=sha1"
else
    echo -e "  \033[0;31m✗\033[0m 签名格式异常"
fi

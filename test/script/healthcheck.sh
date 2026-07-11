#!/bin/bash
# healthcheck — ops 探活
# 依赖: $DB_PASS $DB_USER $DB_NAME $SIDECAR_TOKEN $LOG_DIR_HOST $TEST_HOST (由 test.sh / 环境变量注入)
# 独立运行: DB_PASS=xxx SIDECAR_TOKEN=yyy ./test/script/healthcheck.sh

HOST="${TEST_HOST:-http://localhost}"

echo "[容器状态]"
docker compose ps 2>/dev/null || warn "docker compose 不可用"

echo ""
echo "[Web 服务]"
check "HTTP / 返回 200"      "curl -sf -o /dev/null $HOST/"
check "HTTP /api/login.php"  "curl -sf -o /dev/null $HOST/api/login.php"
check "HTTP /map_info.php"   "curl -sf -o /dev/null $HOST/map_info.php"

echo ""
echo "[PHP 运行时]"
check "pdo_mysql 扩展"  'docker compose exec -T php php -r "exit(extension_loaded(\"pdo_mysql\")?0:1);"'
check "mysqli 扩展"     'docker compose exec -T php php -r "exit(extension_loaded(\"mysqli\")?0:1);"'
check "gd 扩展"         'docker compose exec -T php php -r "exit(extension_loaded(\"gd\")?0:1);"'
check "curl 扩展"       'docker compose exec -T php php -r "exit(extension_loaded(\"curl\")?0:1);"'
check "session 扩展"    'docker compose exec -T php php -r "exit(extension_loaded(\"session\")?0:1);"'
check "json 扩展"       'docker compose exec -T php php -r "exit(extension_loaded(\"json\")?0:1);"'
check "mbstring 扩展"   'docker compose exec -T php php -r "exit(extension_loaded(\"mbstring\")?0:1);"'
check "hash 扩展"       'docker compose exec -T php php -r "exit(extension_loaded(\"hash\")?0:1);"'

echo ""
echo "[数据库]"
check "MySQL 容器运行中" 'test -n "$(docker compose ps mysql -q 2>/dev/null)"'
check "MySQL 端口可达"   'docker compose exec -T php php -r "\$fp=@fsockopen(\"mysql\",3306,\$e,\$s,5);exit(\$fp?0:1);"'

DB_TEST=$(docker compose exec -T php php -r '
try {
    $pdo = new PDO("mysql:host=mysql;dbname='"$DB_NAME"'",
        "'"$DB_USER"'", "'"$DB_PASS"'",
        [PDO::ATTR_TIMEOUT=>5, PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->query("SELECT 1"); echo "OK";
} catch(Exception $e) { echo "FAIL: ".$e->getMessage(); }
' 2>/dev/null)

if echo "$DB_TEST" | grep -q 'OK'; then
    echo -e "  \033[0;32m✓\033[0m MySQL 连接 + 查询 $DB_NAME 库"
else
    echo -e "  \033[0;31m✗\033[0m MySQL 连接: $DB_TEST"
fi

for check_item in "users:—" "maps:cos_url,cos_version" "download_tasks:—" "messages:—"; do
    table="${check_item%%:*}"
    cols="${check_item##*:}"
    EXISTS=$(docker compose exec -T mysql mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW TABLES LIKE '$table';" 2>/dev/null | wc -l)
    if [ "$EXISTS" -ge 2 ]; then
        ok=true
        if [ "$cols" != "—" ]; then
            for col in ${cols//,/ }; do
                COL_OK=$(docker compose exec -T mysql mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW COLUMNS FROM \`$table\` LIKE '$col';" 2>/dev/null | wc -l)
                if [ "$COL_OK" -lt 2 ]; then
                    warn "表 $table 缺少列 $col（执行 mysql/initdb/02-cos.sql）"
                    ok=false
                fi
            done
        fi
        $ok && echo -e "  \033[0;32m✓\033[0m 表 $table 结构正确"
    else
        warn "表 $table 不存在（等待导入数据）"
    fi
done

echo ""
echo "[下载器]"
check "task-daemon 容器运行中" 'test -n "$(docker compose ps task-daemon -q 2>/dev/null)"'
check "task_daemon.php 语法" 'docker compose exec -T task-daemon php -l /var/www/html/task_daemon.php'
check "cos_client.php 语法"       'docker compose exec -T task-daemon php -l /var/www/html/api/cos_client.php'
check "map_manage.php 语法"       'docker compose exec -T php php -l /var/www/html/api/map_manage.php'
check "map_info.php 语法"         'docker compose exec -T php php -l /var/www/html/map_info.php'

COS_LOAD=$(docker compose exec -T task-daemon php -r '
include_once "/var/www/html/api/tools.php";
include_once "/var/www/html/api/cos_client.php";
echo function_exists("cos_configured") ? "OK" : "FAIL";
' 2>/dev/null)
if echo "$COS_LOAD" | grep -q 'OK'; then
    echo -e "  \033[0;32m✓\033[0m cos_client 函数可加载"
else
    echo -e "  \033[0;31m✗\033[0m cos_client 函数加载失败"
fi

COS_CFG=$(docker compose exec -T task-daemon php -r '
include_once "/var/www/html/api/tools.php";
include_once "/var/www/html/api/cos_client.php";
echo cos_configured() ? "configured" : "not_configured";
' 2>/dev/null)
if echo "$COS_CFG" | grep -q 'configured'; then
    echo -e "  \033[0;32m✓\033[0m COS 已配置"
else
    warn "COS 未配置（缺少 COS_SECRET_ID/KEY/BUCKET），上传功能跳过"
fi

LOG_DIR_HOST="${LOG_DIR_HOST:-./web/data/logs}"
if [ -d "$LOG_DIR_HOST" ]; then
    echo -e "  \033[0;32m✓\033[0m 日志目录存在: $LOG_DIR_HOST"
else
    warn "日志目录不存在: $LOG_DIR_HOST"
fi

echo ""
echo "[Sidecar]"
check "health 端点"              'curl -sf -o /dev/null http://localhost:8080/health'
check "containers 端点 (Token)"  'curl -sf -o /dev/null -H "X-Auth-Token: ${SIDECAR_TOKEN:-}" http://localhost:8080/containers'

echo ""
echo "[每日更新]"
TOKEN="${SIDECAR_TOKEN:-}"
if [ -n "$TOKEN" ]; then
    UPDATE_RESP=$(curl -s "$HOST/api/map_manage.php?action=count&token=$TOKEN" 2>/dev/null || true)
    if echo "$UPDATE_RESP" | grep -q '"success":true'; then
        echo -e "  \033[0;32m✓\033[0m map_manage token 认证通过"
    else
        echo -e "  \033[0;31m✗\033[0m map_manage token 认证失败: ${UPDATE_RESP:0:120}"
    fi
else
    warn "SIDECAR_TOKEN 未设置，跳过 token 认证测试"
fi

#!/bin/bash
# l4d2-server 健康检查脚本
# 用法: ./healthcheck.sh
# 返回: 0=全部通过  1=有失败项
set -euo pipefail
cd "$(dirname "$0")/.."

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'
PASS=0
FAIL=0

check() {
    local desc="$1"
    shift
    if bash -c "$*" >/dev/null 2>&1; then
        echo -e "  ${GREEN}✓${NC} $desc"
        ((PASS++)) || true
    else
        echo -e "  ${RED}✗${NC} $desc"
        ((FAIL++)) || true
    fi
}

echo "========================================"
echo "  l4d2-server Health Check"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"

# ---- 容器状态 ----
echo ""
echo "[容器状态]"
docker compose ps 2>/dev/null

# ---- Web HTTP ----
echo ""
echo "[Web 服务]"
check "HTTP / 返回 200"      'curl -sf -o /dev/null http://localhost/'
check "HTTP /api/login.php"  'curl -sf -o /dev/null http://localhost/api/login.php'
check "HTTP /map_info.php"   'curl -sf -o /dev/null http://localhost/map_info.php'

# ---- PHP 运行时 ----
echo ""
echo "[PHP 运行时]"
check "pdo_mysql 扩展" 'docker compose exec -T web php -r "exit(extension_loaded(\"pdo_mysql\")?0:1);"'
check "mysqli 扩展"    'docker compose exec -T web php -r "exit(extension_loaded(\"mysqli\")?0:1);"'
check "gd 扩展"        'docker compose exec -T web php -r "exit(extension_loaded(\"gd\")?0:1);"'
check "curl 扩展"      'docker compose exec -T web php -r "exit(extension_loaded(\"curl\")?0:1);"'
check "session 扩展"   'docker compose exec -T web php -r "exit(extension_loaded(\"session\")?0:1);"'
check "json 扩展"      'docker compose exec -T web php -r "exit(extension_loaded(\"json\")?0:1);"'
check "mbstring 扩展"  'docker compose exec -T web php -r "exit(extension_loaded(\"mbstring\")?0:1);"'
check "exec() 可用"    'docker compose exec -T web php -r "exit(function_exists(\"exec\")?0:1);"'

# ---- 数据库 ----
echo ""
echo "[数据库]"
check "MySQL 容器 healthy" 'docker compose ps mysql --format json | grep -q healthy'
check "MySQL 端口可达"     'docker compose exec -T web php -r "\$fp=@fsockopen(\"mysql\",3306,\$e,\$s,5);exit(\$fp?0:1);"'

DB_TEST=$(docker compose exec -T web php -r '
try {
    $pdo = new PDO("mysql:host=mysql;dbname=steam",
        getenv("DB_USER")?:$_ENV["DB_USER"]?:$_ENV["MYSQL_USER"]?:$_SERVER["MYSQL_USER"],
        getenv("DB_PASSWORD")?:$_ENV["DB_PASSWORD"]?:$_ENV["MYSQL_PASSWORD"]?:$_SERVER["MYSQL_PASSWORD"],
        [PDO::ATTR_TIMEOUT=>5, PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->query("SELECT 1");
    echo "OK";
} catch(Exception $e) {
    echo "FAIL: ".$e->getMessage();
}
' 2>/dev/null)

if echo "$DB_TEST" | grep -q 'OK'; then
    echo -e "  ${GREEN}✓${NC} MySQL 连接 + 查询 steam 库"
    ((PASS++)) || true
else
    echo -e "  ${RED}✗${NC} MySQL 连接: $DB_TEST"
    ((FAIL++)) || true
fi

# 关键表检查
for table in users maps download_tasks messages; do
    EXISTS=$(docker compose exec -T mysql mysql -u steam -p"${MYSQL_PASSWORD:-woyaocuanquan}" steam -e "SHOW TABLES LIKE '$table';" 2>/dev/null | wc -l)
    if [ "$EXISTS" -ge 2 ]; then
        echo -e "  ${GREEN}✓${NC} 表 $table 存在"
        ((PASS++)) || true
    else
        echo -e "  ${YELLOW}~${NC} 表 $table 不存在（等待导入数据）"
    fi
done

# ---- 结果 ----
echo ""
echo "========================================"
printf "  通过: %d  失败: %d\n" $PASS $FAIL
echo "========================================"
[ $FAIL -gt 0 ] && echo -e "${RED}存在失败项，请检查。${NC}" && exit 1
echo -e "${GREEN}全部通过 ✓${NC}"
exit 0

#!/bin/bash
# auto_db — 数据库结构专项检查
# 依赖: $DB_USER $DB_PASS $DB_NAME (由 test.sh 注入)

echo "[cos_url 字段归属]"

# maps 表应有 cos_url + cos_version
check "maps 表有 cos_url 列" \
  "docker compose exec -T mysql mysql -u $DB_USER -p$DB_PASS $DB_NAME -e \"SHOW COLUMNS FROM maps LIKE 'cos_url'\" 2>/dev/null | grep -q cos_url"

check "maps 表有 cos_version 列" \
  "docker compose exec -T mysql mysql -u $DB_USER -p$DB_PASS $DB_NAME -e \"SHOW COLUMNS FROM maps LIKE 'cos_version'\" 2>/dev/null | grep -q cos_version"

# download_tasks 表不应有 cos_url（已还原到 maps）
HAS_COS=$(docker compose exec -T mysql mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW COLUMNS FROM download_tasks LIKE 'cos_url'" 2>/dev/null | wc -l)
if [ "$HAS_COS" -ge 2 ]; then
    echo -e "  \033[0;31m✗\033[0m download_tasks 不应有 cos_url 列（应属于 maps 表）"
else
    echo -e "  \033[0;32m✓\033[0m download_tasks 不含 cos_url（正确归属 maps 表）"
fi

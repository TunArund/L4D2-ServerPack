#!/bin/bash
# auto_api — API 端点 + token 认证
# 依赖: $SIDECAR_TOKEN $TEST_HOST (由 test.sh 注入)

HOST="${TEST_HOST:-http://localhost}"

echo "[Token 认证]"

TOKEN="${SIDECAR_TOKEN:-}"
if [ -z "$TOKEN" ]; then
    warn "SIDECAR_TOKEN 未设置，跳过认证测试"
    exit 0
fi

# token 认证通过
RESP=$(curl -s "$HOST/api/map_manage.php?action=count&token=$TOKEN" 2>/dev/null || true)
if echo "$RESP" | grep -q '"success":true'; then
    echo -e "  \033[0;32m✓\033[0m token 认证通过"
else
    echo -e "  \033[0;31m✗\033[0m token 认证失败: ${RESP:0:120}"
fi

# 无 token 应拒绝（匹配 JSON 结构，避免中文 grep 编码问题）
RESP2=$(curl -s "$HOST/api/map_manage.php?action=count" 2>/dev/null || true)
if echo "$RESP2" | grep -q '"success":false'; then
    echo -e "  \033[0;32m✓\033[0m 无 token 正确拒绝 (success=false)"
else
    echo -e "  \033[0;31m✗\033[0m 无 token 未正确拒绝: ${RESP2:0:120}"
fi

echo ""
echo "[Web 端点]"
check "首页 返回 200"       'test "$(curl -s -o /dev/null -w "%{http_code}" '"$HOST"'/)" = "200"'
check "map_info.php 可访问" 'test "$(curl -s -o /dev/null -w "%{http_code}" '"$HOST"'/map_info.php?id=1)" = "200"'
check "dashboard.php 可访问" 'test "$(curl -s -o /dev/null -w "%{http_code}" '"$HOST"'/dashboard.php)" = "200"'

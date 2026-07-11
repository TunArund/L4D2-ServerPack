#!/bin/bash
# auto_syntax — 全部 PHP 文件语法检查

echo "[PHP 语法]"

for f in web/src/api/*.php web/src/*.php; do
    check "$f" "docker compose exec -T php php -l /var/www/html/${f#web/src/} 2>&1"
done

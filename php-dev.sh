#!/usr/bin/env bash
# ============================================================
# PHP 开发辅助脚本
# 基于已有的 base-php-cli 镜像，无需额外拉取
#
# 用法:
#   ./php-dev.sh             进入容器 shell
#   ./php-dev.sh lint FILE   对指定文件做语法检查
#   ./php-dev.sh lint-all    对 web/src 下所有 PHP 文件做语法检查
#   ./php-dev.sh serve       启动 PHP 内置服务器 (http://localhost:8080)
#   ./php-dev.sh exec CMD    在容器内执行任意命令
# ============================================================
set -euo pipefail

cd "$(dirname "$0")"

COMPOSE_FILES="-f docker-compose.yml -f docker-compose.dev.yml"

case "${1:-shell}" in
  shell|sh)
    docker compose $COMPOSE_FILES run --rm php-dev sh
    ;;
  lint)
    FILE="${2:-}"
    if [ -z "$FILE" ]; then
      echo "Usage: $0 lint <file.php>"
      exit 1
    fi
    docker compose $COMPOSE_FILES run --rm php-dev php -l "/var/www/html/$FILE"
    ;;
  lint-all)
    echo "Checking all PHP files in web/src..."
    docker compose $COMPOSE_FILES run --rm php-dev sh -c '
      find /var/www/html -name "*.php" -print0 | while IFS= read -r -d "" f; do
        result=$(php -l "$f" 2>&1) || true
        if echo "$result" | grep -q "Errors parsing"; then
          echo "FAIL: $f"
          echo "$result"
        else
          echo "OK:   $f"
        fi
      done
    '
    ;;
  serve)
    echo "Starting PHP built-in server at http://localhost:8080 ..."
    echo "Press Ctrl+C to stop."
    docker compose $COMPOSE_FILES run --rm --service-ports php-dev php -S 0.0.0.0:8080 -t /var/www/html
    ;;
  exec)
    shift
    docker compose $COMPOSE_FILES run --rm php-dev "$@"
    ;;
  *)
    echo "Unknown command: $1"
    echo "Usage: $0 {shell|lint <file>|lint-all|serve|exec <cmd>}"
    exit 1
    ;;
esac

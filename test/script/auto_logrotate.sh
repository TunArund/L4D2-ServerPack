#!/bin/bash
# auto_logrotate — 日志按日轮转验证

echo "[日志轮转]"

# 当日日志路径
Y=$(date +%Y); M=$(date +%m); D=$(date +%d)
DAILY_LOG="web/data/logs/downloader_daemon/$Y/$M/${D}.log"
LEGACY_LOG="web/data/logs/downloader_daemon.log"

if [ -f "$DAILY_LOG" ]; then
    if [ -s "$DAILY_LOG" ]; then
        echo -e "  \033[0;32m✓\033[0m 当日轮转日志存在且非空: $DAILY_LOG"
    else
        warn "当日轮转日志为空: $DAILY_LOG（daemon 可能刚启动）"
    fi
else
    warn "当日轮转日志不存在: $DAILY_LOG（检查 downloader 是否正常运行）"
fi

# 旧格式日志应该不再增长（已迁移到轮转）
if [ -f "$LEGACY_LOG" ]; then
    warn "旧格式日志仍存在: $LEGACY_LOG（轮转后 add_log 不再写入此文件，可手动清理）"
fi

# 目录结构
echo ""
check "轮转目录结构正确 (app/YYYY/MM/)" \
  "test -d web/data/logs/downloader_daemon/$Y/$M"

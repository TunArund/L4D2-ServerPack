#!/bin/sh
set -e

D_UID="${APP_UID:-1000}"
D_GID="${APP_GID:-1000}"

# 创建用户/组（Alpine 无 getent，用 grep 替代）
if ! grep -q "^.*:.*:${D_GID}:" /etc/group 2>/dev/null; then
    addgroup -g "$D_GID" taskdaemon 2>/dev/null || true
fi
if ! id -u "$D_UID" >/dev/null 2>&1; then
    adduser -u "$D_UID" -G "$(getent group "$D_GID" | cut -d: -f1)" -D -s /bin/sh taskdaemon 2>/dev/null || true
fi

exec setpriv --reuid="$D_UID" --regid="$D_GID" --init-groups \
    php /var/www/html/task_daemon.php

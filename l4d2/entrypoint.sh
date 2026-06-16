#!/bin/bash
set -e

# 按传入的 UID/GID（默认 1000）动态创建运行用户
STEAM_UID="${UID:-1000}"
STEAM_GID="${GID:-1000}"

# 如果组不存在则创建
if ! getent group "$STEAM_GID" >/dev/null; then
    groupadd -g "$STEAM_GID" steam
fi

# 如果用户不存在则创建
if ! getent passwd "$STEAM_UID" >/dev/null; then
    useradd -m -u "$STEAM_UID" -g "$STEAM_GID" -s /bin/bash steam
fi

# 确保 steamclient.so 可用
mkdir -p /home/steam/.steam/sdk32
ln -sf /home/steam/l4d2/linux32/steamclient.so /home/steam/.steam/sdk32/steamclient.so

# 降权运行 srcds
cd /home/steam/l4d2
exec setpriv --reuid="$STEAM_UID" --regid="$STEAM_GID" --init-groups \
    ./srcds_run ${L4D2_EXTRA_ARGS:-"$@"}

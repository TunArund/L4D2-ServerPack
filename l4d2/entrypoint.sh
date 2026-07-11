#!/bin/bash
set -e

# 按传入的 APP_UID/APP_GID（默认 1000）动态创建运行用户
STEAM_UID="${APP_UID:-1000}"
STEAM_GID="${APP_GID:-1000}"

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

# 检查游戏文件是否存在
if [[ ! -f /home/steam/l4d2/srcds_run ]]; then
    echo "=============================================="
    echo "  错误: 游戏文件未找到！"
    echo "  srcds_run 不存在于 /home/steam/l4d2/"
    echo ""
    echo "  请先在宿主机下载游戏文件（~9GB）："
    echo "    ./l4d2.sh install"
    echo ""
    echo "  如果已下载到其他位置，请设置 GAME_DIR："
    echo "    export GAME_DIR=/path/to/l4d2/src"
    echo "    docker compose up -d l4d2"
    echo "=============================================="
    exit 1
fi

# 降权运行 srcds
cd /home/steam/l4d2
exec setpriv --reuid="$STEAM_UID" --regid="$STEAM_GID" --init-groups \
    ./srcds_run ${L4D2_EXTRA_ARGS:-"$@"}

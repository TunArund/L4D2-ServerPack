#!/bin/bash
set -euo pipefail

# ============================================================
# L4D2 游戏服务器资产管理
#   ./l4d2.sh install   — 首次安装（steamcmd + 游戏下载）
#   ./l4d2.sh update    — 更新游戏到最新版本
#   ./l4d2.sh help      — 显示帮助
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
WORK_DIR=/home/steam
INSTALL_DIR="$SCRIPT_DIR/l4d2/src"
STEAM_DIR="$WORK_DIR/Steam"
STEAMCMD_URL="http://media.steampowered.com/installer/steamcmd_linux.tar.gz"
APP_ID=222860   # Left 4 Dead 2

# ============================================================
# 系统依赖（仅 Ubuntu/Debian）
# ============================================================
install_deps() {
    echo ">>> 安装 32 位库依赖..."
    sudo dpkg --add-architecture i386
    sudo apt-get update -qq
    sudo apt-get install -y -qq libc6:i386 lib32z1 lib32gcc-s1
    sudo apt-get clean
}

# ============================================================
# 下载并安装 steamcmd
# ============================================================
install_steamcmd() {
    if [[ -x "$STEAM_DIR/steamcmd.sh" ]]; then
        echo "  steamcmd 已存在，跳过下载"
        return 0
    fi

    echo ">>> 下载 steamcmd..."
    mkdir -p "$STEAM_DIR"
    cd "$STEAM_DIR"
    wget -q "$STEAMCMD_URL"
    tar -xzf steamcmd_linux.tar.gz
    rm steamcmd_linux.tar.gz

    # 首次运行以完成自更新
    ./steamcmd.sh +quit 2>/dev/null || true
    echo "  steamcmd 安装完成"
}

# ============================================================
# 下载/更新游戏文件
# ============================================================
download_game() {
    local mode="${1:-update}"  # install or update

    echo ">>> ${mode} L4D2 游戏文件 (app $APP_ID) ..."
    echo "  安装目录: $INSTALL_DIR"
    echo "  预计下载: ~9GB，请耐心等待..."

    mkdir -p "$INSTALL_DIR"

    cd "$STEAM_DIR"
    ./steamcmd.sh \
        +force_install_dir "$INSTALL_DIR" \
        +login anonymous \
        +@sSteamCmdForcePlatformType windows \
        +app_update "$APP_ID" \
        +@sSteamCmdForcePlatformType linux \
        +app_update ${GAME_ID} validate \
        +quit

}

# ============================================================
# 首次安装：依赖 → steamcmd → 游戏
# ============================================================
install() {
    echo "=============================================="
    echo "  L4D2 首次安装"
    echo "=============================================="
    install_deps
    install_steamcmd
    download_game install
    echo ""
    echo ">>> 安装完成！"
}

# ============================================================
# 更新游戏
# ============================================================
update() {
    echo "=============================================="
    echo "  L4D2 游戏更新"
    echo "=============================================="
    download_game update
    echo ""
    echo ">>> 更新完成！请重启游戏容器使更新生效:"
    echo "    docker compose restart l4d2 l4d2-versus"
}

# ============================================================
# 帮助
# ============================================================
help() {
    echo "用法: ./l4d2.sh <command>"
    echo ""
    echo "命令:"
    echo "  install  首次安装（系统依赖 + steamcmd + 下载游戏）"
    echo "  update   更新游戏到最新版本"
    echo "  help     显示此帮助"
    echo ""
    echo "游戏文件位置: $INSTALL_DIR"
}

# ============================================================
# Main
# ============================================================
case "${1:-help}" in
    install)  install ;;
    update)   update ;;
    help|*)   help ;;
esac

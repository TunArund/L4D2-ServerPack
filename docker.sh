#!/bin/bash
set -euo pipefail

# ============================================================
# Docker 管理脚本 — 安装 / 构建 / 推送 / 启停 一站式
#
# 用法:
#   ./docker.sh install         自动安装 Docker (Ubuntu/Debian)
#   ./docker.sh build           本地构建
#   ./docker.sh up              启动所有服务
#   ./docker.sh down            停止所有服务
#   ./docker.sh restart [svc]   重启服务
#   ./docker.sh logs [svc]      查看日志
#   ./docker.sh pull            从 ghcr.io 拉取镜像
#   ./docker.sh push [ver]      构建并推送到 ghcr.io
#   ./docker.sh clean           清理镜像/容器/缓存
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# ============================================================
# Docker 自动安装 (Ubuntu/Debian)
# ============================================================
ensure_docker() {
    if command -v docker &>/dev/null && docker info &>/dev/null 2>&1; then
        return 0
    fi

    echo ">>> Docker 未安装，自动安装..."

    local distro
    distro="$(lsb_release -is 2>/dev/null || echo '')"
    if [[ "$distro" != "Ubuntu" && "$distro" != "Debian" ]]; then
        echo "错误: 仅支持 Ubuntu/Debian 自动安装"
        echo "请手动安装: https://docs.docker.com/engine/install/"
        exit 1
    fi

    echo "  系统: $distro $(lsb_release -rs 2>/dev/null || echo '')"

    # 卸载冲突包
    for pkg in docker.io docker-doc docker-compose docker-compose-v2 podman-docker containerd runc; do
        dpkg -l "$pkg" &>/dev/null 2>&1 && sudo apt-get remove -y -qq "$pkg" 2>/dev/null || true
    done

    # 安装 Docker
    sudo apt-get update -qq
    sudo apt-get install -y -qq ca-certificates curl gnupg lsb-release
    sudo install -m 0755 -d /etc/apt/keyrings
    sudo curl -fsSL "https://download.docker.com/linux/${distro,,}/gpg" -o /etc/apt/keyrings/docker.asc
    sudo chmod a+r /etc/apt/keyrings/docker.asc

    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
https://download.docker.com/linux/${distro,,} $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

    sudo apt-get update -qq
    sudo apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

    if ! groups "$USER" | grep -q docker; then
        sudo usermod -aG docker "$USER"
        echo "  [注意] 已加入 docker 组，需执行 newgrp docker 或重新登录"
    fi

    # 配置国内镜像加速
    if [[ ! -f /etc/docker/daemon.json ]]; then
        sudo tee /etc/docker/daemon.json <<'DAEMON'
{
  "registry-mirrors": [
    "https://docker.1ms.run",
    "https://docker.xuanyuan.me"
  ]
}
DAEMON
        sudo systemctl restart docker
        echo "  [加速] Docker Hub 镜像源已配置"
    fi

    echo ">>> Docker 安装完成"
}

# ============================================================
# 工具函数
# ============================================================
_get_env() {
    local var="$1" default="${2:-}" val
    if [[ -f .env ]]; then
        val="$(grep -E "^${var}=" .env | tail -1 | sed 's/^[^=]*=//')"
        [[ -n "$val" ]] && echo "$val" || echo "$default"
    else
        echo "$default"
    fi
}

_ghcr_login() {
    local user="${1:-}" token="${2:-}"
    if [[ -z "$token" || "$token" == "change_me" ]]; then
        echo "错误: 推送需要有效的 GITHUB_TOKEN"
        echo "获取: GitHub → Settings → Developer settings → Tokens (classic) → write:packages"
        exit 1
    fi
    echo ">>> 登录 ghcr.io (用户: $user) ..."
    echo "$token" | docker login ghcr.io --username "$user" --password-stdin
}

# ============================================================
# 命令实现
# ============================================================

cmd_install() {
    ensure_docker
}

cmd_build() {
    ensure_docker
    local registry="${REGISTRY:-$(_get_env REGISTRY)}"
    echo ">>> 本地构建 (REGISTRY=${registry:-空}) ..."

    # 先构建基础镜像（服务 FROM 依赖，BuildKit 并行解析需要本地已有）
    echo "  [1/2] 基础镜像 (--no-cache) ..."
    REGISTRY="$registry" docker compose build --no-cache base-php-fpm base-php-cli

    # 再构建服务镜像
    echo "  [2/2] 服务镜像 ..."
    REGISTRY="$registry" docker compose build l4d2 nginx php downloader sidecar

    echo ">>> 构建完成，执行 ./docker.sh up 启动"
}

cmd_up() {
    ensure_docker
    echo ">>> 启动服务 ..."
    docker compose up -d
    docker compose ps
}

cmd_down() {
    ensure_docker
    echo ">>> 停止服务 ..."
    docker compose down
}

cmd_restart() {
    ensure_docker
    local svc="${1:-}"
    if [[ -n "$svc" ]]; then
        echo ">>> 重启 $svc ..."
        docker compose restart "$svc"
    else
        echo ">>> 重启所有服务 ..."
        docker compose restart
    fi
}

cmd_logs() {
    ensure_docker
    local svc="${1:-}"
    docker compose logs -f --tail="${2:-100}" "$svc"
}

cmd_pull() {
    ensure_docker
    echo ">>> 拉取镜像 ..."
    docker compose pull
    echo ">>> 拉取完成，执行 ./docker.sh up 启动"
}

cmd_push() {
    ensure_docker
    local version="${1:-latest}"
    local user="${GITHUB_USER:-$(_get_env GITHUB_USER)}"
    local token="${GITHUB_TOKEN:-$(_get_env GITHUB_TOKEN)}"
    local registry="${REGISTRY:-$(_get_env REGISTRY ghcr.io/tunarund/)}"
    [[ "$registry" != */ ]] && registry="${registry}/"

    _ghcr_login "$user" "$token"

    echo ""
    echo ">>> 构建基础镜像 ..."
    REGISTRY="$registry" docker compose build --no-cache base-php-fpm base-php-cli

    echo ""
    echo ">>> 构建服务镜像 ..."
    REGISTRY="$registry" docker compose build l4d2 nginx php downloader sidecar

    # 推送清单
    local all_images=(
        "l4d2-base-php-fpm"
        "l4d2-base-php-cli"
        "l4d2-server-game"
        "l4d2-nginx"
        "l4d2-php"
        "l4d2-downloader"
        "l4d2-sidecar"
    )

    echo ""
    echo ">>> 推送到 $registry ..."
    for img in "${all_images[@]}"; do
        echo "  $registry$img:$version"
        docker push "$registry$img:$version"
    done

    echo ""
    echo "=============================================="
    echo "  推送完成"
    echo "=============================================="
    echo ""
    echo "设为公开:"
    echo "  https://github.com/TunArund/L4D2-ServerPack/pkgs/container/l4d2-server-game"
    echo "  → Package Settings → Change visibility → Public"
    echo ""
    echo "公开后拉取: docker pull ghcr.io/tunarund/l4d2-server-game:latest"
}

cmd_clean() {
    ensure_docker
    echo ">>> 清理前磁盘占用:"
    docker system df
    echo ""
    docker image prune -f
    docker builder prune -f
    echo ""
    echo ">>> 清理后磁盘占用:"
    docker system df
}

# ============================================================
# 命令路由
# ============================================================
show_help() {
    echo "用法: ./docker.sh <命令> [参数]"
    echo ""
    echo "命令:"
    echo "  install         自动安装 Docker (Ubuntu/Debian)"
    echo "  build           本地构建所有镜像"
    echo "  up              启动所有服务"
    echo "  down            停止所有服务"
    echo "  restart [svc]   重启服务 (不指定则全部)"
    echo "  logs [svc]      查看日志"
    echo "  pull            从 ghcr.io 拉取镜像"
    echo "  push [ver]      构建并推送到 ghcr.io (需 GITHUB_TOKEN)"
    echo "  clean           清理悬空镜像和构建缓存"
    echo ""
    echo "示例:"
    echo "  ./docker.sh install          # 新机器第一步"
    echo "  ./docker.sh build && ./docker.sh up   # 本地构建并启动"
    echo "  ./docker.sh logs l4d2 50     # 查看 l4d2 最后 50 行日志"
    echo "  ./docker.sh push latest      # 推送镜像"
}

case "${1:-help}" in
    install)  cmd_install ;;
    build)    cmd_build ;;
    up)       cmd_up ;;
    down)     cmd_down ;;
    restart)  cmd_restart "${2:-}" ;;
    logs)     cmd_logs "${2:-}" "${3:-100}" ;;
    pull)     cmd_pull ;;
    push)     cmd_push "${2:-latest}" ;;
    clean)    cmd_clean ;;
    help|*)   show_help ;;
esac

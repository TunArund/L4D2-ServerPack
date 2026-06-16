#!/bin/bash
set -euo pipefail

# ============================================================
# GitHub Container Registry (ghcr.io) 构建 & 推送脚本
#   1. 检查/自动安装 Docker
#   2. 从 .env 读取凭据
#   3. 构建 → 推送到 ghcr.io
#
# 使用前准备:
#   GitHub → Settings → Developer settings → Personal access tokens
#   → Generate new token (classic) → 勾选 write:packages, delete:packages
#   在 .env 中填入 GITHUB_USER 和 GITHUB_TOKEN
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# ============================================================
# 0. 检查 Docker 是否安装（Ubuntu/Debian 自动安装）
# ============================================================
check_docker() {
    if command -v docker &>/dev/null && docker info &>/dev/null 2>&1; then
        return 0
    fi

    echo ">>> Docker 未安装或未启动，尝试自动安装..."

    local distro
    distro="$(lsb_release -is 2>/dev/null || echo '')"
    if [[ "$distro" != "Ubuntu" && "$distro" != "Debian" ]]; then
        echo "错误: 仅支持 Ubuntu/Debian 自动安装 Docker"
        echo "请手动安装: https://docs.docker.com/engine/install/"
        exit 1
    fi

    echo "  检测到系统: $distro $(lsb_release -rs 2>/dev/null || echo '')"

    local old_pkgs=("docker.io" "docker-doc" "docker-compose" "docker-compose-v2" "podman-docker" "containerd" "runc")
    local to_remove=()
    for pkg in "${old_pkgs[@]}"; do
        if dpkg -l "$pkg" &>/dev/null 2>&1; then
            to_remove+=("$pkg")
        fi
    done
    if [[ ${#to_remove[@]} -gt 0 ]]; then
        echo "  卸载冲突包: ${to_remove[*]}"
        sudo apt-get remove -y -qq "${to_remove[@]}" 2>/dev/null || true
    fi

    sudo apt-get update -qq
    sudo apt-get install -y -qq ca-certificates curl gnupg lsb-release

    sudo install -m 0755 -d /etc/apt/keyrings
    sudo curl -fsSL "https://download.docker.com/linux/${distro,,}/gpg" -o /etc/apt/keyrings/docker.asc
    sudo chmod a+r /etc/apt/keyrings/docker.asc

    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${distro,,} $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

    sudo apt-get update -qq
    sudo apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

    if ! groups "$USER" | grep -q docker; then
        sudo usermod -aG docker "$USER"
        echo "  [注意] 已将 $USER 加入 docker 组，可能需要重新登录生效"
    fi

    echo ">>> Docker 安装完成"
    echo ""
}

check_docker

# ============================================================
# 1. 加载配置
# ============================================================
VERSION="${1:-latest}"

_get_env() {
    local var="$1"
    local default="${2:-}"
    if [[ -f .env ]]; then
        local val
        val="$(grep -E "^${var}=" .env | tail -1 | sed 's/^[^=]*=//')"
        [[ -n "$val" ]] && echo "$val" || echo "$default"
    else
        echo "$default"
    fi
}

GITHUB_USER="${GITHUB_USER:-$(_get_env GITHUB_USER)}"
GITHUB_TOKEN="${GITHUB_TOKEN:-$(_get_env GITHUB_TOKEN)}"

# 推送目标（ghcr.io 无需提取 host，直接就是 ghcr.io）
TARGET_REGISTRY="${REGISTRY:-$(_get_env REGISTRY ghcr.io/tunarund/)}"
[[ "$TARGET_REGISTRY" != */ ]] && TARGET_REGISTRY="${TARGET_REGISTRY}/"

# --- 基础镜像（先构建，服务镜像依赖它们） ---
BASE_IMAGES=(
    "l4d2-base-php-fpm:l4d2-base-php-fpm"
    "l4d2-base-php-cli:l4d2-base-php-cli"
)

# --- 服务镜像 ---
IMAGES=(
    "l4d2:l4d2-server-game"
    "nginx:l4d2-nginx"
    "php:l4d2-php"
    "downloader:l4d2-downloader"
    "sidecar:l4d2-sidecar"
)

# ============================================================
# 2. 检查凭据
# ============================================================
if [[ -z "${GITHUB_TOKEN:-}" ]]; then
    echo "错误: .env 中未设置 GITHUB_TOKEN"
    echo ""
    echo "获取方法:"
    echo "  GitHub → Settings → Developer settings → Personal access tokens"
    echo "  → Tokens (classic) → Generate new token (classic)"
    echo "  → 勾选: write:packages, delete:packages"
    echo "  → 生成后复制 token"
    echo ""
    echo "然后在 .env 中填入:"
    echo "  GITHUB_USER=你的GitHub用户名"
    echo "  GITHUB_TOKEN=ghp_xxxxxxxxxxxx"
    exit 1
fi

if [[ -z "${GITHUB_USER:-}" ]]; then
    echo "错误: .env 中未设置 GITHUB_USER"
    echo "设置为你的 GitHub 用户名，例如: GITHUB_USER=TunArun"
    exit 1
fi

# ============================================================
# 3. 登录 ghcr.io
# ============================================================
echo ">>> 登录 ghcr.io (用户: $GITHUB_USER) ..."
echo "$GITHUB_TOKEN" | docker login ghcr.io --username "$GITHUB_USER" --password-stdin

# ============================================================
# 4. 构建全部镜像（base-php → 服务镜像，Compose 自动处理顺序）
# ============================================================
echo ""
echo ">>> 构建镜像 (REGISTRY=$TARGET_REGISTRY) ..."

REGISTRY="$TARGET_REGISTRY" docker compose build

# ============================================================
# 6. 推送
# ============================================================
echo ""
echo ">>> 推送到 $TARGET_REGISTRY ..."

ALL_IMAGES=("${BASE_IMAGES[@]}" "${IMAGES[@]}")
for entry in "${ALL_IMAGES[@]}"; do
    REPO="${entry##*:}"
    IMAGE="${TARGET_REGISTRY}${REPO}:${VERSION}"

    echo ""
    echo "--- $IMAGE ---"
    docker push "$IMAGE"
    echo "  ✓ 推送完成"
done

# ============================================================
# 6. 完成
# ============================================================
echo ""
echo "=============================================="
echo "  全部推送完成"
echo "=============================================="
echo ""
echo "镜像列表:"
for entry in "${ALL_IMAGES[@]}"; do
    REPO="${entry##*:}"
    echo "  ${TARGET_REGISTRY}${REPO}:${VERSION}"
done
echo ""
echo "设为公开（首次推送后需要手动设置）:"
echo "  https://github.com/TunArun/L4D2-ServerPack/pkgs/container/l4d2-server-game/settings"
echo "  → Danger Zone → Change visibility → Public"
echo ""
echo "公开后任何人可直接拉取:"
echo "  docker pull ghcr.io/tunarund/l4d2-server-game:latest"
echo ""
echo "生产环境部署:"
echo "  1. git clone <repo> && cd l4d2-server"
echo "  2. cp .env.prod .env"
echo "  3. docker compose pull     # 公开镜像无需登录"
echo "  4. docker compose up -d"

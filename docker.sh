#!/bin/bash
set -euo pipefail

# ============================================================
# 华为云 SWR 镜像构建 & 推送脚本
#   1. 检查/自动安装 Docker
#   2. 从 .env 读取凭据
#   3. 构建 → 打标签 → 推送到华为云 SWR
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

    # 检测系统
    local distro
    distro="$(lsb_release -is 2>/dev/null || echo '')"
    if [[ "$distro" != "Ubuntu" && "$distro" != "Debian" ]]; then
        echo "错误: 仅支持 Ubuntu/Debian 自动安装 Docker"
        echo "请手动安装: https://docs.docker.com/engine/install/"
        exit 1
    fi

    echo "  检测到系统: $distro $(lsb_release -rs 2>/dev/null || echo '')"

    # 卸载旧版本（如果存在）
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

    # 安装依赖
    sudo apt-get update -qq
    sudo apt-get install -y -qq ca-certificates curl gnupg lsb-release

    # 添加 Docker 官方 GPG key
    sudo install -m 0755 -d /etc/apt/keyrings
    sudo curl -fsSL "https://download.docker.com/linux/${distro,,}/gpg" -o /etc/apt/keyrings/docker.asc
    sudo chmod a+r /etc/apt/keyrings/docker.asc

    # 添加 apt 源
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${distro,,} $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

    # 安装 Docker
    sudo apt-get update -qq
    sudo apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

    # 将当前用户加入 docker 组
    if ! groups "$USER" | grep -q docker; then
        sudo usermod -aG docker "$USER"
        echo "  [注意] 已将 $USER 加入 docker 组，可能需要重新登录生效"
    fi

    echo ">>> Docker 安装完成"
    echo ""
}

check_docker

# ============================================================
# 1. 加载配置（只取 SWR 相关变量，不 source 整个 .env）
# ============================================================
VERSION="${1:-latest}"

_get_env() {
    # 从 .env 读取指定变量的值
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

SWR_USER="${SWR_USER:-$(_get_env SWR_USER)}"
SWR_PASSWORD="${SWR_PASSWORD:-$(_get_env SWR_PASSWORD)}"

# 推送目标 REGISTRY（从 .env 读，为空则用默认值）
TARGET_REGISTRY="${REGISTRY:-$(_get_env REGISTRY swr.cn-east-3.myhuaweicloud.com/tunarund/)}"
# 确保末尾有 /
[[ "$TARGET_REGISTRY" != */ ]] && TARGET_REGISTRY="${TARGET_REGISTRY}/"

# 从 REGISTRY 提取登录主机名 (去掉路径只保留 host)
# swr.cn-east-3.myhuaweicloud.com/tunarund/ → swr.cn-east-3.myhuaweicloud.com
SWR_HOST="${TARGET_REGISTRY%%/*}"

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
if [[ -z "${SWR_PASSWORD:-}" ]]; then
    echo "错误: .env 中未设置 SWR_PASSWORD"
    echo ""
    echo "获取方法: 华为云控制台 → 容器镜像服务 SWR → 我的镜像"
    echo "       → (右上角) 登录指令 → 生成长期有效 docker login 指令"
    echo ""
    echo "登录指令格式:"
    echo "  docker login -u <region>@<AK> -p <password> $SWR_HOST"
    echo ""
    echo "然后在 .env 中填入:"
    echo "  SWR_USER=<region>@<AK>"
    echo "  SWR_PASSWORD=<password>"
    exit 1
fi

if [[ -z "${SWR_USER:-}" ]]; then
    echo "错误: .env 中未设置 SWR_USER"
    echo "格式: <region>@<AK>  例如 cn-east-3@HST3W2FURMU1ARJ4W9MQ"
    exit 1
fi

# ============================================================
# 3. 登录 SWR
# ============================================================
echo ">>> 登录 $SWR_HOST ..."
echo "$SWR_PASSWORD" | docker login "$SWR_HOST" --username "$SWR_USER" --password-stdin

# ============================================================
# 4. 构建基础镜像（其他服务依赖它们）
# ============================================================
echo ""
echo ">>> 构建基础镜像 ..."

for entry in "${BASE_IMAGES[@]}"; do
    REPO="${entry#*:}"
    IMAGE="${TARGET_REGISTRY}${REPO}:${VERSION}"

    echo "  - $IMAGE"

    docker build \
        -t "$REPO:latest" \
        -t "$IMAGE" \
        -f "base-php/Dockerfile.${REPO##*-}" \
        base-php/
done

# ============================================================
# 5. 构建服务镜像
# ============================================================
echo ""
echo ">>> 构建服务镜像 (REGISTRY=$TARGET_REGISTRY) ..."
for entry in "${IMAGES[@]}"; do
    SERVICE="${entry%%:*}"
    echo "  - $SERVICE"
done

REGISTRY="$TARGET_REGISTRY" docker compose build "${IMAGES[@]%%:*}"

# ============================================================
# 6. 推送（基础镜像 + 服务镜像）
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
echo "生产环境部署:"
echo "  1. git clone <repo> && cd l4d2-server"
echo "  2. cp .env.prod .env && vim .env   # 填入密码"
echo "  3. docker login $SWR_HOST"
echo "  4. docker compose pull"
echo "  5. docker compose up -d"

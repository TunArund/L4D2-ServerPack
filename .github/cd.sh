#!/bin/bash
set -euo pipefail

REMO_HOST=steam@tencent
REMO_PROJ_DIR=/home/steam/l4d2-server
SERVICE=${1:-l4d2-versus}
HASH_DIR=/tmp/l4d2-deploy-hash
mkdir -p "$HASH_DIR"

# ---------- helper: compare hash, upload only if changed ----------
changed() {
    local label=$1 path=$2
    local hash old_hash_file="$HASH_DIR/$label.sha256"
    if [[ -f "$path" ]]; then
        hash=$(sha256sum "$path" | awk '{print $1}')
    elif [[ -d "$path" ]]; then
        hash=$(cd "$path" && find . -type f -exec sha256sum {} \; | sort -k2 | sha256sum | awk '{print $1}')
    else
        hash=$(cat "$path" 2>/dev/null | sha256sum | awk '{print $1}')
    fi
    if [[ -f "$old_hash_file" ]] && [[ "$(cat "$old_hash_file")" == "$hash" ]]; then
        return 1  # unchanged
    fi
    echo "$hash" > "$old_hash_file"
    return 0  # changed
}

# ---------- 1. 镜像 ----------
echo "=== 1/5 检查镜像变更 ==="
EXPORTED=0
CUR_ID=$(docker image inspect l4d2-server-game --format '{{.ID}}' 2>/dev/null)
if changed image <(echo "$CUR_ID"); then
    echo "导出镜像..."
    docker save l4d2-server-game | gzip > /tmp/l4d2-server-game.tar.gz
    EXPORTED=1
else
    echo "镜像未变更"
fi

# ---------- 2. 远端目录 ----------
echo "=== 2/5 远端准备目录 ==="
ssh "$REMO_HOST" "mkdir -p $REMO_PROJ_DIR/l4d2/data"

# ---------- 3. 配置文件（hash 检查） ----------
echo "=== 3/5 上传配置 ==="

if changed compose docker-compose.yml; then
    echo "  docker-compose.yml → 远端"
    scp docker-compose.yml "$REMO_HOST:$REMO_PROJ_DIR/"
fi

# .env 始终同步（远端 UID/GID 自动注入）
echo "  生成远端 .env"
REMOTE_UID=$(ssh "$REMO_HOST" 'id -u')
REMOTE_GID=$(ssh "$REMO_HOST" 'id -g')
sed "s/^UID=.*/UID=$REMOTE_UID/; s/^GID=.*/GID=$REMOTE_GID/" .env.example > /tmp/.env.deploy
scp /tmp/.env.deploy "$REMO_HOST:$REMO_PROJ_DIR/.env"

if changed versus-data l4d2/data/versus; then
    echo "  versus 数据 → 远端"
    tar czf /tmp/versus-data.tar.gz -C l4d2/data versus
    scp /tmp/versus-data.tar.gz "$REMO_HOST":/tmp/
    ssh "$REMO_HOST" "tar xzf /tmp/versus-data.tar.gz -C $REMO_PROJ_DIR/l4d2/data/ && rm /tmp/versus-data.tar.gz"
fi

# ---------- 4. 镜像上传 ----------
if [[ "$EXPORTED" == "1" ]]; then
    echo "=== 4/5 上传镜像 ==="
    scp /tmp/l4d2-server-game.tar.gz "$REMO_HOST":/tmp/
    ssh "$REMO_HOST" "docker load < /tmp/l4d2-server-game.tar.gz && rm /tmp/l4d2-server-game.tar.gz"
else
    echo "=== 4/5 镜像未变更 ==="
fi

# ---------- 5. 启动 ----------
echo "=== 5/5 远端启动 $SERVICE ==="
ssh "$REMO_HOST" "cd $REMO_PROJ_DIR && docker compose up -d $SERVICE"

echo "=== 完成 ==="

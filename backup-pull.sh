#!/bin/bash
set -euo pipefail

# ============================================================
# 远程备份拉取脚本（迁移/灾备）
#   在已配置 SSH 免密登录的服务器上执行备份，拉取到本地，
#   可再在本机还原（SQL + 冷备 tgz）。
#
# 依赖:
#   - 本机已 ssh 免密登录远端（ssh-copy-id 配置过）
#   - 远端已部署最新版代码（mysql.sh 输出到 backup/，含 --no-tablespaces 修复）
#   - restore/full 需在本机（目标机）项目根目录运行，且 MySQL 已初始化
#
# 用法:
#   ./backup-pull.sh <user@host> <远端项目目录> [命令]
#
#   命令（默认 all）:
#     backup    远端执行备份（mysql.sh backup + 冷备 tar）
#     pull      拉取远端 backup/ 到本地 backup/
#     all       备份 + 拉取（安全，默认）
#     restore   本地还原（覆盖 DB 与 l4d2/data，需确认）
#     full      备份 + 拉取 + 还原（危险，需确认）
#
# 可选环境变量:
#   SSH_PORT   远端 SSH 端口（默认 22）
#
# 示例:
#   ./backup-pull.sh steam@1.2.3.4 /home/steam/L4D2-ServerPack
#   ./backup-pull.sh steam@1.2.3.4 /home/steam/L4D2-ServerPack full
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

REMOTE_HOST="${1:-}"
REMOTE_DIR="${2:-}"
CMD="${3:-all}"

BACKUP_DIR="$SCRIPT_DIR/backup"      # 本地备份目录（与 mysql.sh 的 BACKUP_DIR 一致）
SSH_PORT="${SSH_PORT:-22}"

usage() {
    cat <<'EOF'
用法: ./backup-pull.sh <user@host> <远端项目目录> [命令]

命令（默认 all）:
  backup    远端执行备份（mysql.sh backup + 冷备 tar）
  pull      拉取远端 backup/ 到本地 backup/
  all       备份 + 拉取（安全，默认）
  restore   本地还原（覆盖 DB 与 l4d2/data，需确认）
  full      备份 + 拉取 + 还原（危险，需确认）

可选环境变量:
  SSH_PORT  远端 SSH 端口（默认 22）

示例:
  ./backup-pull.sh steam@1.2.3.4 /home/steam/L4D2-ServerPack
  ./backup-pull.sh steam@1.2.3.4 /home/steam/L4D2-ServerPack full
EOF
}

# 远端执行备份：数据库 dump + 冷备 tar（排除 workshop 地图）
remote_backup() {
    echo ">>> 远端 $REMOTE_HOST 执行备份 ..."
    ssh -p "$SSH_PORT" "$REMOTE_HOST" "set -e
        cd '$REMOTE_DIR'
        ./mysql.sh backup
        trap 'docker compose start task-daemon l4d2 l4d2-versus' EXIT
        docker compose stop task-daemon l4d2 l4d2-versus
        tar czf backup/l4d2-data.tgz --exclude='workshop' l4d2/data/
    "
    echo ">>> 远端备份完成"
}

# 拉取远端 backup/ 到本地 backup/
pull() {
    mkdir -p "$BACKUP_DIR"
    echo ">>> 拉取 $REMOTE_HOST:$REMOTE_DIR/backup/ → $BACKUP_DIR ..."
    if command -v rsync >/dev/null 2>&1; then
        # rsync 需远端也安装；支持断点续传（--partial）
        rsync -avz --partial --progress \
            -e "ssh -p $SSH_PORT" \
            "$REMOTE_HOST:$REMOTE_DIR/backup/" "$BACKUP_DIR/"
    else
        scp -P "$SSH_PORT" -r "$REMOTE_HOST:$REMOTE_DIR/backup/." "$BACKUP_DIR/"
    fi
    echo ">>> 拉取完成"
}

# 本地还原（覆盖数据库 + l4d2/data）
restore() {
    local sql tgz ans
    sql="$(ls -t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | head -1 || true)"
    tgz="$BACKUP_DIR/l4d2-data.tgz"

    [[ -n "$sql" ]] || { echo "错误: $BACKUP_DIR 下没有 *.sql.gz 备份" >&2; exit 1; }
    [[ -f "$tgz" ]]  || { echo "错误: 缺少 $tgz" >&2; exit 1; }

    echo ">>> 将用以下备份还原（覆盖本地数据）:"
    echo "    SQL: $sql"
    echo "    TGZ: $tgz"
    if ! read -rp "输入 yes 确认还原: " ans; then
        echo "已取消" >&2
        exit 1
    fi
    [[ "$ans" == "yes" ]] || { echo "已取消"; exit 0; }

    ./mysql.sh restore "$sql"
    tar xzf "$tgz"
    echo ">>> 还原完成。地图可再执行:"
    echo "    docker compose exec task-daemon php /var/www/html/bin/restore_from_cos.php"
}

# 无参数 → 用法
if [[ $# -eq 0 ]]; then
    usage >&2
    exit 1
fi

# help 出现在任意参数位 → 打印帮助
for a in "$@"; do
    case "$a" in
        help|-h|--help) usage; exit 0 ;;
    esac
done

# 校验远端参数（restore 不需要）
case "$CMD" in
    restore) ;;
    *)
        [[ -n "$REMOTE_HOST" ]] || { echo "缺少 <user@host>" >&2; usage >&2; exit 1; }
        [[ -n "$REMOTE_DIR" ]]  || { echo "缺少 <远端目录>" >&2; usage >&2; exit 1; }
        ;;
esac

case "$CMD" in
    backup)  remote_backup ;;
    pull)    pull ;;
    all)     remote_backup; pull ;;
    restore) restore ;;
    full)    remote_backup; pull; restore ;;
    *)       usage >&2; exit 1 ;;
esac

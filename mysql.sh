#!/bin/bash
set -euo pipefail

# ============================================================
# MySQL 连接脚本
#   自动读取 .env 配置连接 steam 数据库。
#   优先通过 docker exec 进容器执行（无需安装客户端），
#   容器未运行时回退到本地 mysql-client。
#
# 用法:
#   ./mysql.sh                 交互式 shell
#   ./mysql.sh < file.sql      执行 SQL 文件
#   ./mysql.sh -e "query"      执行单条查询
#   ./mysql.sh install         安装 mysql-client (Debian/Ubuntu)
#   ./mysql.sh help            显示帮助
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

CONTAINER_NAME="l4d2-mysql"

# ============================================================
# 从 .env 读取配置
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

MYSQL_DATABASE="$(_get_env MYSQL_DATABASE steam)"
MYSQL_USER="$(_get_env MYSQL_USER steam)"
MYSQL_PASSWORD="$(_get_env MYSQL_PASSWORD change_me)"

# ============================================================
# 检测 Docker 容器是否运行
# ============================================================
container_running() {
    docker info &>/dev/null 2>&1 && \
    docker ps --format '{{.Names}}' 2>/dev/null | grep -q "^${CONTAINER_NAME}$"
}

# ============================================================
# 安装 mysql-client (Debian/Ubuntu)
# ============================================================
install_mysql_client() {
    local distro
    distro="$(lsb_release -is 2>/dev/null || echo '')"

    if [[ "$distro" != "Ubuntu" && "$distro" != "Debian" ]]; then
        echo "错误: 仅支持 Ubuntu/Debian 自动安装 mysql-client"
        echo ""
        echo "当前系统: ${distro:-未知}"
        echo "请手动安装 mysql-client 或使用 docker exec 方式连接:"
        echo "  docker exec -it $CONTAINER_NAME mysql -u $MYSQL_USER -p -D $MYSQL_DATABASE"
        exit 1
    fi

    echo ">>> 安装 mysql-client (${distro})..."
    sudo apt-get update -qq
    sudo apt-get install -y -qq mysql-client
    echo "  mysql-client 安装完成"
}

# ============================================================
# 构建 docker exec 参数
# ============================================================
_docker_exec() {
    # stdin 是终端 → 交互模式；否则 → 管道模式
    if [[ -t 0 ]]; then
        docker exec -it "$CONTAINER_NAME" mysql \
            --default-character-set=utf8mb4 \
            -u "$MYSQL_USER" \
            "-p${MYSQL_PASSWORD}" \
            -D "$MYSQL_DATABASE" \
            "$@"
    else
        docker exec -i "$CONTAINER_NAME" mysql \
            --default-character-set=utf8mb4 \
            -u "$MYSQL_USER" \
            "-p${MYSQL_PASSWORD}" \
            -D "$MYSQL_DATABASE" \
            "$@"
    fi
}

# ============================================================
# 本地 mysql 客户端
# ============================================================
_native_mysql() {
    mysql \
        --default-character-set=utf8mb4 \
        -u "$MYSQL_USER" \
        "-p${MYSQL_PASSWORD}" \
        -D "$MYSQL_DATABASE" \
        "$@"
}

# ============================================================
# 命令实现
# ============================================================

cmd_install() {
    install_mysql_client
}

cmd_connect() {
    # 方式 1: Docker 容器（推荐，无需安装客户端）
    if container_running; then
        _docker_exec "$@"
        return $?
    fi

    # 方式 2: 本地 mysql 客户端
    if command -v mysql &>/dev/null; then
        _native_mysql "$@"
        return $?
    fi

    # 都不可用 — 提示安装
    echo ">>> 未检测到可用的 MySQL 连接方式"
    echo ""
    echo "  原因: MySQL 容器 ($CONTAINER_NAME) 未运行，且本地未安装 mysql-client"
    echo ""
    echo "  请选择:"
    echo "    1) 启动 MySQL 容器:  docker compose up -d mysql"
    echo "    2) 安装 mysql-client: ./mysql.sh install"
    echo ""
    read -rp "  是否现在安装 mysql-client (Debian/Ubuntu)? [y/N] " answer
    if [[ "$answer" =~ ^[Yy] ]]; then
        install_mysql_client || exit 1
        # 安装后重试
        if command -v mysql &>/dev/null; then
            _native_mysql "$@"
        fi
    else
        echo "已取消"
        exit 1
    fi
}

show_help() {
    echo "用法: ./mysql.sh [命令] [参数]"
    echo ""
    echo "命令:"
    echo "  (无参数)       打开交互式 MySQL shell"
    echo "  < file.sql     执行 SQL 文件（管道输入）"
    echo "  -e 'query'     执行单条查询"
    echo "  install        安装 mysql-client (Debian/Ubuntu)"
    echo "  help           显示此帮助"
    echo ""
    echo "连接信息 (来自 .env):"
    echo "  数据库: $MYSQL_DATABASE"
    echo "  用户:   $MYSQL_USER"
    echo "  连接:   docker exec → $CONTAINER_NAME"
    echo ""
    echo "示例:"
    echo "  ./mysql.sh                                    # 交互式 shell"
    echo "  ./mysql.sh < mysql/initdb/02-cos.sql          # 执行 SQL 迁移"
    echo "  ./mysql.sh -e 'SHOW TABLES'                   # 单条查询"
    echo "  ./mysql.sh -e 'SELECT id, title FROM maps'"
    echo "  ./mysql.sh install                            # 安装客户端"
}

# ============================================================
# 命令路由
# ============================================================
if [[ $# -eq 0 ]]; then
    if [[ ! -t 0 ]]; then
        # stdin 有内容 (如 ./mysql.sh < file.sql)
        cmd_connect < /dev/stdin
    else
        cmd_connect
    fi
else
    case "${1:-}" in
        install)          cmd_install ;;
        help|--help|-h)   show_help ;;
        *)                cmd_connect "$@" ;;
    esac
fi

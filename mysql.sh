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
#   ./mysql.sh passwd <root|app> [--reset] [新密码|--random]  修改/重置 root 或应用库密码
#   ./mysql.sh backup [文件]    备份数据库（默认 .sql.gz）
#   ./mysql.sh restore <文件>   从备份恢复数据库
#   ./mysql.sh help            显示帮助
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

CONTAINER_NAME="l4d2-mysql"
MYSQL_IMAGE="mysql:8.0"   # 需与 docker-compose.yml 中 mysql 服务镜像一致

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

# ============================================================
# 写入 .env（更新已有行，不存在则追加）
# ============================================================
_set_env() {
    local var="$1" val="$2" line found=0 tmp
    if [[ ! -f .env ]]; then
        echo "警告: 未找到 .env 文件，无法同步 ${var}" >&2
        return 1
    fi
    tmp="$(mktemp)"
    while IFS= read -r line || [[ -n "$line" ]]; do
        if [[ "$line" == "${var}="* ]]; then
            printf '%s=%s\n' "$var" "$val"
            found=1
        else
            printf '%s\n' "$line"
        fi
    done < .env > "$tmp"
    [[ "$found" -eq 1 ]] || printf '%s=%s\n' "$var" "$val" >> "$tmp"
    mv "$tmp" .env
}

# ============================================================
# SQL 字符串转义（反斜杠、单引号）
# ============================================================
_sql_escape() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/'"'"'/\\'"'"'/g'
}

# ============================================================
# 读取新密码（参数 > --random > 交互输入）；仅向 stdout 输出密码
# ============================================================
_read_new_password() {
    local arg="${1:-}" label="${2:-root}" pw confirm
    if [[ "$arg" == "--random" ]]; then
        pw="$(openssl rand -hex 16)"
    elif [[ -n "$arg" ]]; then
        pw="$arg"
    else
        if [[ ! -t 0 ]]; then
            echo "错误: 非交互模式需提供新密码或 --random" >&2
            return 1
        fi
        read -rsp "新 ${label} 密码: " pw; echo >&2
        read -rsp "再次输入确认: " confirm; echo >&2
        if [[ "$pw" != "$confirm" ]]; then
            echo "错误: 两次输入不一致" >&2
            return 1
        fi
    fi
    if [[ -z "$pw" ]]; then
        echo "错误: 密码不能为空" >&2
        return 1
    fi
    printf '%s' "$pw"
}

# ============================================================
# 等待容器内 MySQL 就绪（可选带密码验证）
# ============================================================
_wait_mysql() {
    local name="$1" pw="${2:-}" i
    for i in $(seq 1 30); do
        if [[ -n "$pw" ]]; then
            docker exec -i "$name" mysql -uroot "-p${pw}" -e "SELECT 1;" &>/dev/null && return 0
        else
            docker exec -i "$name" mysql -uroot -e "SELECT 1;" &>/dev/null && return 0
        fi
        sleep 1
    done
    return 1
}

# ============================================================
# 生成针对某账号的 ALTER USER 语句（root 含 localhost 与 %，app 仅 %）
# ============================================================
_alter_sql() {
    local target="$1" sql_new="$2"
    if [[ "$target" == "root" ]]; then
        printf "ALTER USER 'root'@'localhost' IDENTIFIED BY '%s'; ALTER USER 'root'@'%%' IDENTIFIED BY '%s';" "$sql_new" "$sql_new"
    else
        printf "ALTER USER '%s'@'%%' IDENTIFIED BY '%s';" "$MYSQL_USER" "$sql_new"
    fi
}

# ============================================================
# 用新密码验证连接（app 通过 TCP 127.0.0.1 模拟应用连接方式）
# ============================================================
_verify_conn() {
    local target="$1" pw="$2"
    if [[ "$target" == "root" ]]; then
        docker exec -i "$CONTAINER_NAME" mysql -uroot "-p${pw}" -e "SELECT 1;" &>/dev/null
    else
        docker exec -i "$CONTAINER_NAME" mysql -u"$MYSQL_USER" "-p${pw}" -h 127.0.0.1 -e "SELECT 1;" &>/dev/null
    fi
}

# ============================================================
# 轮询验证连接（容器重启后就绪等待）
# ============================================================
_wait_verify() {
    local target="$1" pw="$2" i
    for i in $(seq 1 30); do
        if _verify_conn "$target" "$pw"; then
            return 0
        fi
        sleep 1
    done
    return 1
}

MYSQL_DATABASE="$(_get_env DB_DATABASE steam)"
MYSQL_USER="$(_get_env DB_USER steam)"
MYSQL_PASSWORD="$(_get_env DB_PASSWORD change_me)"

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
    # -h 127.0.0.1 走 TCP 匹配 steam@'%'（镜像默认 skip-name-resolve，socket 匹配不到 localhost 账号）
    if [[ -t 0 ]]; then
        docker exec -it "$CONTAINER_NAME" mysql \
            --default-character-set=utf8mb4 \
            -h 127.0.0.1 \
            -u "$MYSQL_USER" \
            "-p${MYSQL_PASSWORD}" \
            -D "$MYSQL_DATABASE" \
            "$@"
    else
        docker exec -i "$CONTAINER_NAME" mysql \
            --default-character-set=utf8mb4 \
            -h 127.0.0.1 \
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

# ============================================================
# 备份 MySQL 数据库（mysqldump 逻辑备份，输出 .sql 或 .sql.gz）
# ============================================================
_dump_db() {
    docker exec -i "$CONTAINER_NAME" mysqldump \
        -h 127.0.0.1 -u "$MYSQL_USER" "-p${MYSQL_PASSWORD}" \
        --single-transaction --triggers --set-gtid-purged=OFF \
        --default-character-set=utf8mb4 \
        "$MYSQL_DATABASE"
}

cmd_backup() {
    local out="${1:-}" size
    [[ -n "$out" ]] || out="${MYSQL_DATABASE}-$(date +%Y%m%d-%H%M%S).sql.gz"

    if ! container_running; then
        echo "错误: MySQL 容器 ($CONTAINER_NAME) 未运行" >&2
        echo "  请先启动: docker compose up -d mysql" >&2
        exit 1
    fi

    echo ">>> 备份数据库 $MYSQL_DATABASE → $out ..."
    if [[ "$out" == *.gz ]]; then
        if ! _dump_db | gzip > "$out"; then
            rm -f "$out"
            echo "错误: 备份失败，请检查 .env 的 DB_PASSWORD 是否正确" >&2
            exit 1
        fi
    else
        if ! _dump_db > "$out"; then
            rm -f "$out"
            echo "错误: 备份失败，请检查 .env 的 DB_PASSWORD 是否正确" >&2
            exit 1
        fi
    fi

    size="$(du -h "$out" | cut -f1)"
    echo ">>> 备份完成: $out ($size)"
}

# ============================================================
# 恢复 MySQL 数据库（导入备份，覆盖同名表）
# ============================================================
cmd_restore() {
    local file="${1:-}"
    if [[ -z "$file" ]]; then
        echo "用法: ./mysql.sh restore <备份文件>" >&2
        exit 1
    fi
    if [[ ! -f "$file" ]]; then
        echo "错误: 文件不存在: $file" >&2
        exit 1
    fi
    if ! container_running; then
        echo "错误: MySQL 容器 ($CONTAINER_NAME) 未运行" >&2
        echo "  请先启动: docker compose up -d mysql" >&2
        exit 1
    fi

    echo ">>> 恢复数据库 $MYSQL_DATABASE ← $file（覆盖同名表）..."
    if [[ "$file" == *.gz ]]; then
        if ! gunzip -c "$file" | docker exec -i "$CONTAINER_NAME" mysql \
            -h 127.0.0.1 -u "$MYSQL_USER" "-p${MYSQL_PASSWORD}" \
            --default-character-set=utf8mb4 "$MYSQL_DATABASE"; then
            echo "错误: 恢复失败" >&2
            exit 1
        fi
    else
        if ! docker exec -i "$CONTAINER_NAME" mysql \
            -h 127.0.0.1 -u "$MYSQL_USER" "-p${MYSQL_PASSWORD}" \
            --default-character-set=utf8mb4 "$MYSQL_DATABASE" < "$file"; then
            echo "错误: 恢复失败" >&2
            exit 1
        fi
    fi
    echo ">>> 恢复完成"
}

# ============================================================
# 统一密码管理
#   用法: ./mysql.sh passwd <root|app> [--reset] [新密码|--random]
#   root   root 管理员密码
#   app    应用库用户 (DB_USER) 密码
#   --reset    忘记当前密码时强制重置（--skip-grant-tables，保留数据）
# ============================================================
cmd_passwd() {
    local target="${1:-}" reset=0 random=0 pw_arg="" new_pw sql_new arg label env_var
    shift || true

    for arg in "$@"; do
        case "$arg" in
            --reset)  reset=1 ;;
            --random) random=1 ;;
            *)        pw_arg="$arg" ;;
        esac
    done

    case "$target" in
        root) label="root";                     env_var="DB_ROOT_PASSWORD" ;;
        app)  label="应用库用户 ${MYSQL_USER}"; env_var="DB_PASSWORD" ;;
        *)    _passwd_usage; return 1 ;;
    esac

    if [[ "$random" -eq 1 ]]; then
        new_pw="$(_read_new_password --random "$label")" || return 1
    else
        new_pw="$(_read_new_password "$pw_arg" "$label")" || return 1
    fi
    sql_new="$(_sql_escape "$new_pw")"

    if [[ "$reset" -eq 1 ]]; then
        _passwd_reset "$target" "$new_pw" "$sql_new" "$label" "$env_var"
    else
        _passwd_change "$target" "$new_pw" "$sql_new" "$label" "$env_var"
    fi
}

_passwd_usage() {
    echo "用法: ./mysql.sh passwd <root|app> [--reset] [新密码|--random]" >&2
    echo "  root        修改/重置 root 管理员密码" >&2
    echo "  app         修改/重置应用库用户 ($MYSQL_USER) 密码" >&2
    echo "  --reset     忘记当前密码时强制重置（跳过授权，保留数据）" >&2
    echo "  --random    随机生成新密码 (openssl rand -hex 16)" >&2
}

# 在线修改（以 root 身份连接，需知道 root 当前密码）
_passwd_change() {
    local target="$1" new_pw="$2" sql_new="$3" label="$4" env_var="$5" root_pw alter_sql
    root_pw="$(_get_env DB_ROOT_PASSWORD change_me)"
    alter_sql="$(_alter_sql "$target" "$sql_new")"

    if ! container_running; then
        echo "错误: MySQL 容器 ($CONTAINER_NAME) 未运行" >&2
        echo "  请先启动: docker compose up -d mysql" >&2
        exit 1
    fi

    echo ">>> 正在修改 ${label} 密码..."
    if ! docker exec -i "$CONTAINER_NAME" mysql -uroot "-p${root_pw}" -e "$alter_sql"; then
        echo "错误: 修改失败，root 密码不正确（.env 中的 DB_ROOT_PASSWORD 与实际不符）" >&2
        echo "  如果已忘记 root 密码，请用: ./mysql.sh passwd ${target} --reset" >&2
        exit 1
    fi

    _set_env "$env_var" "$new_pw" || exit 1

    if _verify_conn "$target" "$new_pw"; then
        echo ">>> ${label} 密码修改成功，已同步到 .env"
    else
        echo ">>> ${label} 密码已修改并同步到 .env，但验证连接失败，请手动确认" >&2
    fi
}

# 强制重置（忘记密码，--skip-grant-tables 保留数据）
_passwd_reset() {
    local target="$1" new_pw="$2" sql_new="$3" label="$4" env_var="$5" reset_name data_dir alter_sql
    reset_name="${CONTAINER_NAME}-reset"
    data_dir="${SCRIPT_DIR}/mysql/data"
    alter_sql="$(_alter_sql "$target" "$sql_new")"

    if [[ ! -d "$data_dir" ]] || [[ -z "$(ls -A "$data_dir" 2>/dev/null)" ]]; then
        echo "错误: MySQL 数据目录不存在或为空 ($data_dir)" >&2
        echo "  请先正常启动一次完成初始化: docker compose up -d mysql" >&2
        exit 1
    fi

    echo ">>> 停止 MySQL 容器（短暂停库）..."
    docker compose stop mysql &>/dev/null || true

    echo ">>> 以跳过授权模式启动临时容器..."
    docker rm -f "$reset_name" &>/dev/null || true
    if ! docker run -d --name "$reset_name" \
        -v "${data_dir}:/var/lib/mysql" \
        "$MYSQL_IMAGE" --skip-grant-tables --skip-networking; then
        docker compose up -d mysql &>/dev/null || true
        echo "错误: 无法启动临时容器" >&2
        exit 1
    fi

    echo ">>> 等待 MySQL 就绪..."
    if ! _wait_mysql "$reset_name"; then
        _passwd_abort "$reset_name"
        echo "错误: 临时容器未在超时时间内就绪" >&2
        exit 1
    fi

    echo ">>> 重置 ${label} 密码..."
    if ! docker exec -i "$reset_name" mysql -uroot \
        -e "FLUSH PRIVILEGES; ${alter_sql}"; then
        _passwd_abort "$reset_name"
        echo "错误: ${label} 密码重置失败" >&2
        exit 1
    fi

    docker rm -f "$reset_name" &>/dev/null || true

    _set_env "$env_var" "$new_pw" || { docker compose up -d mysql &>/dev/null || true; exit 1; }

    echo ">>> 重启 MySQL 容器..."
    docker compose up -d mysql

    echo ">>> 验证新密码..."
    if _wait_verify "$target" "$new_pw"; then
        echo ">>> ${label} 密码重置成功，已同步到 .env"
    else
        echo ">>> ${label} 密码已重置并同步到 .env，但验证连接失败，请手动确认" >&2
    fi
}

_passwd_abort() {
    local name="$1"
    docker rm -f "$name" &>/dev/null || true
    echo ">>> 恢复 MySQL 容器..."
    docker compose up -d mysql &>/dev/null || true
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
    echo "  passwd <root|app>  修改/重置 root 或应用库密码（--reset 强制重置，--random 随机生成）"
    echo "  backup [文件]  备份数据库（默认 steam-时间戳.sql.gz）"
    echo "  restore <文件> 从备份恢复数据库（覆盖同名表）"
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
    echo "  ./mysql.sh passwd root                        # 交互式修改 root 密码"
    echo "  ./mysql.sh passwd app --reset --random        # 忘记密码时随机重置应用库密码"
    echo "  ./mysql.sh backup                             # 备份数据库到 .sql.gz"
    echo "  ./mysql.sh restore steam-20260816-000000.sql.gz  # 恢复备份"
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
        passwd)           cmd_passwd "${@:2}" ;;
        backup)           cmd_backup "${2:-}" ;;
        restore)          cmd_restore "${2:-}" ;;
        help|--help|-h)   show_help ;;
        *)                cmd_connect "$@" ;;
    esac
fi

#!/bin/bash
# l4d2-server 测试入口
# 用法:
#   ./test.sh             全部：healthcheck → auto_* → manual_web
#   ./test.sh healthcheck 仅 ops 探活
#   ./test.sh auto        仅自动化
#   ./test.sh manual      仅人工引导
#   ./test.sh db          单个模块（匹配文件名）
set -euo pipefail

# ============================================================
# 配置管理
# ============================================================
cd "$(dirname "$0")"
PROJECT_ROOT="$(pwd)"

if [ -f .env ]; then set -a; source .env; set +a; fi

export PROJECT_ROOT
export DB_PASS="${MYSQL_PASSWORD:-}"
export DB_USER="${MYSQL_USER:-steam}"
export DB_NAME="${MYSQL_DATABASE:-steam}"
export SIDECAR_TOKEN="${SIDECAR_TOKEN:-}"
export TEST_HOST="${TEST_HOST:-http://localhost}"

TS=$(date '+%Y-%m-%d_%H-%M-%S')
LOG_DIR="test/log/$TS"
mkdir -p "$LOG_DIR"
export LOG_DIR

# ============================================================
# 共享函数 — export 给子脚本使用
# ============================================================
check() {
    local desc="$1"; shift
    if bash -c "$*" >/dev/null 2>&1; then
        echo -e "  \033[0;32m✓\033[0m $desc"
        return 0
    else
        echo -e "  \033[0;31m✗\033[0m $desc"
        return 1
    fi
}
export -f check

warn() {
    echo -e "  \033[1;33m~\033[0m $1"
}
export -f warn

banner() {
    echo ""; echo "========================================"
    echo "  $1"
    echo "========================================"
}
export -f banner

# ============================================================
# 调度
# ============================================================
SCRIPT_DIR="test/script"
PASS=0; FAIL=0

run_script() {
    local label="$1" path="$SCRIPT_DIR/$2"
    if [ ! -f "$path" ]; then warn "脚本不存在: $path"; return; fi
    echo ""; echo "▸ $label ($2)"
    echo "──────────────────────────────────────────"
    local log="$LOG_DIR/${2%.sh}.log"
    if bash "$path" > >(tee -a "$log") 2>&1; then
        echo -e "  ── \033[0;32m$label 通过\033[0m"
        ((PASS++)) || true
    else
        echo -e "  ── \033[0;31m$label 失败\033[0m"
        ((FAIL++)) || true
    fi
}

run_manual() {
    local path="$SCRIPT_DIR/$1"
    echo ""; echo "▸ 人工引导 ($1)"
    echo "──────────────────────────────────────────"
    bash "$path" 2>&1 | tee -a "$LOG_DIR/${1%.sh}.log"
}

# ---- 主流程 ----
{
    banner "l4d2-server Test Suite"
    echo "  时间: $(date '+%Y-%m-%d %H:%M:%S')"
    echo "  日志: $LOG_DIR/"

    MODE="${1:-all}"

    case "$MODE" in
        all)
            run_script "Ops 探活"      "healthcheck.sh"
            run_script "PHP 语法"      "auto_syntax.sh"
            run_script "数据库结构"     "auto_db.sh"
            run_script "API 认证"      "auto_api.sh"
            run_script "COS 模块"      "auto_cos.sh"
            run_script "日志轮转"      "auto_logrotate.sh"
            run_manual                 "manual_web.sh"
            ;;
        healthcheck) run_script "Ops 探活" "healthcheck.sh" ;;
        auto)
            run_script "PHP 语法"      "auto_syntax.sh"
            run_script "数据库结构"     "auto_db.sh"
            run_script "API 认证"      "auto_api.sh"
            run_script "COS 模块"      "auto_cos.sh"
            run_script "日志轮转"      "auto_logrotate.sh"
            ;;
        manual) run_manual "manual_web.sh" ;;
        *)
            matched=false
            for f in "$SCRIPT_DIR"/*.sh; do
                base=$(basename "$f")
                if [[ "$base" == *"$MODE"* ]]; then
                    if [[ "$base" == manual_web* ]]; then run_manual "$base"
                    else
                        label=$(basename "$base" .sh | sed 's/^auto_//; s/_/ /g')
                        run_script "$label" "$base"
                    fi
                    matched=true; break
                fi
            done
            if ! $matched; then
                echo "未知模块: $MODE"
                echo "可用: all | healthcheck | auto | manual | syntax | db | api | cos | logrotate"
                exit 1
            fi
            ;;
    esac

    echo ""
    echo "========================================"
    echo "  自动化: 通过 $PASS  失败 $FAIL"
    echo "  日志:   $LOG_DIR/"
    echo "========================================"

    [ $FAIL -gt 0 ] && exit 1
    exit 0
} 2>&1 | tee "$LOG_DIR/summary.log"

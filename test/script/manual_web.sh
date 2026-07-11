#!/bin/bash
# manual_web — 网页操作人工引导
# 依赖: $TEST_HOST (由 test.sh 注入)

HOST="${TEST_HOST:-http://localhost}"

echo ""
echo "================================================"
echo "  网页操作人工验证"
echo "  每个步骤操作完成后按 Enter 继续"
echo "  输入 s 跳过  输入 f 标记失败"
echo "================================================"
echo ""

step() {
    local num="$1" desc="$2" url="$3" expect="$4"
    echo "── 步骤 $num ──────────────────────────────────"
    echo "  操作: $desc"
    echo "  URL:  $url"
    echo "  预期: $expect"
    echo -n "  [Enter=通过 / s=跳过 / f=失败] "
    read -r ans
    case "$ans" in
        f|F) echo -e "  \033[0;31m✗ 用户标记失败\033[0m" ;;
        s|S) echo -e "  \033[1;33m~ 跳过\033[0m" ;;
        *)   echo -e "  \033[0;32m✓ 通过\033[0m" ;;
    esac
    echo ""
}

step 1 \
  "浏览器打开首页，确认页面正常加载" \
  "$HOST/" \
  "看到导航栏、地图展示列表"

step 2 \
  "注册新用户（如已有账号可跳过）" \
  "$HOST/" \
  "填写用户名/邮箱/密码 → 提交 → 检查邮箱验证码"

step 3 \
  "登录" \
  "$HOST/api/login.php" \
  "输入账号密码 → 登录成功跳转首页"

step 4 \
  "地图展示页 — 浏览地图列表" \
  "$HOST/dashboard.php" \
  "看到地图卡片列表，含订阅数、大小、下载状态等信息"

step 5 \
  "地图详情 — 检查双 CDN 下载按钮" \
  "$HOST/map_info.php?id=<有效的 map_id>" \
  "看到「腾讯CDN（直链下载）」蓝色按钮（COS 上传后出现）+「SteamCDN（直链下载）」绿色按钮。
   如无可用的 map_id，在 dashboard 点击任意地图进入。
   如尚无地图数据，此步骤跳过即可。"

echo ""
echo "================================================"
echo "  人工验证完成"
echo "  提示: 自动化测试日志 → test/log/ 目录"
echo "================================================"

# 优化路线图

> 2026-07-16 | 基于代码审计 + dashboard 重构后的讨论
> 执行顺序：P0 安全/架构 → P1 基础设施 → P2 bug → P3 增强 → P4 架构 → P5 重重构

## P0 — 安全 + 架构基础 ✅

### 1. billboard.php `ORDER BY` 白名单加固 ✅
**文件：** `web/src/billboard.php`
`get_map_info()` 中 `$order_by` / `$order` 添加白名单校验，防止动态拼接注入。清理遗漏的 `include_once LIB_DIR . 'core.php'`。

### 2. 消息 API 合并 ✅
**文件：** `web/src/api/messages.php`（新），删除 `get_unread_count.php` + `get_unread_messages.php`
通过 `?type=count|list` 合并为一个端点，`navbar.js` fetch URL 已更新。

---

## P1 — 基础设施 ✅

### 3. bootstrap.php 统一前置加载 ✅
**文件：** `web/src/bootstrap.php`（新），`nginx/data/conf.d/common.inc`（+1 行），20 个文件清理重复 include
nginx `fastcgi_param PHP_VALUE "auto_prepend_file=..."` 注入，每个 PHP 请求自动完成 session / CSRF / config / core / auth 加载。

### 4. conn_db() 单例化 ✅
**文件：** `web/src/lib/core.php`
`static $pdo` 单例，同一请求多次调用共享连接。3 行改动。

### 5. CSS 关注点分离 ✅
**文件：** `navbar.php` → `global.css`，`dashboard.php` → `dashboard.css`，`billboard.php` → `billboard.css`
3 个页面的内联 `<style>` 块提取到独立 CSS 文件。函数保持内联——无复用场景不拆模板。

### 6. 全局背景图收敛 ✅
**文件：** `global.css` + `index.php`
默认纯色背景（`#f5f5f5`），`blue-guy.jpg` 仅首页使用。非主页卡片可读性提升。

### 7. login.php 补漏 ✅
**文件：** `web/src/api/login.php`
补 `$pdo = conn_db()`（已有 bug），删重复 `session_start()`（bootstrap 已启动）。

---

## P2 — Bug 修复

### 8. dashboard 修复（RX/TX 标反 + 进度布局优化）
**文件：** `web/src/static/js/custom/dashboard.js`

**RX/TX 标反：** `bytes_recv_rate_per_sec`（接收=下载=↓）存入 `rx`，但 `updateNetChart(tx, rx)` 传参颠倒，`#val-net-rx`/`#val-net-tx` 的 DOM 赋值也交叉使用，导致标签与实际数据对调。

```javascript
// 修正
updateNetChart(rx, tx);                                          // dataset[0]=rx=下载, dataset[1]=tx=上传
document.querySelector('#val-net-rx').textContent = formatBits(rx) + '/s';
document.querySelector('#val-net-tx').textContent = formatBits(tx) + '/s';
```

**进度布局：** 下载/上传任务卡片中速度、剩余时间、百分比各独占一行，中等屏幕下卡片过高。改为单行 `速度 · 剩余 · 百分比`。

**改动量：** ~10 行 | **风险：** 极低

---

### 9. map_info 数据展示修复（description 换行 + subscriptions 填充）
**涉及文件：** `web/src/lib/steam.php`、`web/src/lib/map.php`、`web/src/map_info.php`

两个 Steam API 数据相关的展示 bug：

**description 无换行：** Steam Workshop 返回的 description 含 `\n`，展示时未转 `<br>`。在 `map_info.php` 展示处加 `nl2br()`，确认 steam.php→map.php 存储链未丢弃换行符。

**subscriptions 为空：** 订阅数在所有地图中为 0。怀疑 `steamworkshopdownloader.io` API 响应格式变更，`format_steam_item()` 解析路径不再匹配。需抓取实际响应对比。

**改动量：** ~8 行（含排查） | **风险：** 低

---

### 10. API JSON Content-Type 统一
**涉及文件：** 10 个 `api/*.php` + `web/src/lib/core.php`

所有 API 端点手写 `header('Content-Type: application/json')`。收拢到 `json_error()` / `json_success()` 中自动设置，删除各端点的重复 header 行。

**改动量：** ~10 行 | **风险：** 极低

---

## P3 — 功能增强

### 11. dashboard 日志查看改进
**文件：** `web/src/dashboard.php`、`web/src/static/js/custom/dashboard.js`

当前：展开 collapse → fetch 固定 100 行 → 静态展示。

改进：
- 自动刷新 toggle（每 5s fetch 最新日志）
- 滚动到底部按钮（新增日志时自动跟随）
- `ERROR`/`WARNING` 关键词着色

**改动量：** ~40 行 | **风险：** 低

---

### 12. 自定义 CSS → Bootstrap 工具类收缩
**涉及文件：** `global.css`、`dashboard.css`、`billboard.css`

3 个 CSS 文件共 ~60 行，评估用 Bootstrap 5 工具类替代：

| 自定义 | 替代方案 |
|--------|---------|
| `.bg-color-darkblue / -darkerblue` | Bootstrap `bg-dark` + CSS 变量 |
| `.chart-wrap { height:180px }` | 内联 `style="height:180px"` |
| `.my-end` hover 动画 | `transition` 需少量自定义保留 |
| `.log-toggle .triangle::before` | 保留（伪元素不可用工具类） |
| 全局 `body` | 保留（全局默认值不适合工具类） |

目标：~60 行 → ~15 行必要的自定义 CSS。

**改动量：** ~30 行 | **风险：** 低

---

## P4 — 前端架构统一

### 13. 内联 JS 提取 + ES module 收尾 ✅

**内联 JS 提取：** `billboard.php` 的 `trans2form` → `billboard.js`，`personal.php` 的 `markRead`/`batchDelete` → `personal.js`。

**分页统一（评估后不做）：** billboard 是服务端分页（`<a href>` → 页面跳转），map_manage/map_request 是客户端回调（`<button>` → renderPagination）。范式不同，无法统一渲染逻辑。

**ES module：**
- `tools.js` 移除 `window.*` 回退（全站已统一用 `import`，无消费者走全局变量）
- `index.js` 转 ES module（`window.copy` 保留 onclick 兼容）
- `navbar.js` 不做——全局 CSRF 拦截器 + `relocation` onclick 调用 + 作用域 bug，硬转风险高于收益

**commit:** 待提交

---

## P5 — 后端 + 重构收尾

### 14. CLI 脚本路径隔离
**文件：** `nginx/data/conf.d/common.inc`
`task_daemon.php` 和 `lib/debug.php` 是 CLI-only 脚本，不应暴露在 nginx 可访问路径下。加 `location = /task_daemon.php { deny all; }` 等规则，或移到 `web/src/` 之外。

**改动量：** nginx 2 行 | **风险：** 极低

---

### 15. map_info.php 模板拆分
**文件：** `web/src/map_info.php`
CarouselGenerator 类 + 评论区 HTML 混在页面文件中。与 billboard/personal 不同，map_info 的 HTML 复杂度较高（轮播图拼接、评论列表循环），拆分模板有实际收益：
```
templates/map_detail.php     ← 轮播 + 详情信息
templates/comment_list.php   ← 评论区
```
需先用 #9 修完数据展示 bug 再做拆分，避免在错误数据上建模板。

**改动量：** ~200 行重组 | **风险：** 低

---

## 汇总

| # | 优先级 | 分类 | 条目 | 改动量 | 状态 |
|---|--------|------|------|--------|------|
| 1 | P0 | 安全 | billboard `ORDER BY` 白名单 | 5 行 | ✅ |
| 2 | P0 | 架构 | 消息 API 合并 | ~30 行 | ✅ |
| 3 | P1 | 架构 | bootstrap.php 统一前置加载 | 20 文件 | ✅ |
| 4 | P1 | 性能 | conn_db() 单例化 | 3 行 | ✅ |
| 5 | P1 | UI | CSS 分离（global + dashboard + billboard） | 3 新文件 | ✅ |
| 6 | P1 | UI | 全局背景图收敛到首页 | 2 文件 | ✅ |
| 7 | P1 | bug | login.php 补 `$pdo` + 删重复 `session_start` | 2 行 | ✅ |
| 8 | P2 | bug | dashboard RX/TX 标反 + 进度布局 | ~10 行 | ✅ |
| 9 | P2 | bug | map_info description 换行 + subscriptions 填充 | ~8 行 | ✅ |
| 10 | P2 | 架构 | API JSON header 统一 | ~10 行 | ✅ |
| 11 | P3 | 功能 | dashboard 日志查看改进 | ~40 行 | ✅ |
| 12 | P3 | UI | 自定义 CSS 清理（删死代码 + 30 个未用 Bootstrap 文件） | ~30 行 | ✅ |
| 13 | P4 | 架构 | 内联 JS 提取 + ES module 收尾 | ~40 行 | ✅ |
| 14 | P5 | 安全 | CLI 脚本路径隔离 | 2 行 | 待做 |
| 15 | P5 | 架构 | map_info 模板拆分 | ~200 行 | 待做 |

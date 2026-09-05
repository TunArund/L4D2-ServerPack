# CHANGELOG

## 2026-09-05 — 监控中心任务面板与收件箱体验改进

### 监控中心任务面板（dashboard）

- 下载 / COS 上传任务卡片补全标题：任务查询 `LEFT JOIN maps` 取地图 `title` 与 `size`（等待阶段 `total_bytes` 为 0 时回退地图 size）
- 下载任务非进度态（等待/成功/失败）不再只显示 steamid，改为显示地图标题 + 大小
- 标题过长单行截断（`text-truncate` + `min-width:0`），不再换行挤开时间；悬浮 `title` 属性显示完整标题
- 简单卡片（等待/成功/失败）将「大小」与「时间」互换位置：大小字段更短，给标题留出更多空间，时间移至第二行
- 任务标题可点击跳转 `map_info.php?id=map_id`（地图记录不存在时仅显示纯文本，不生成链接）

### 收件箱（personal.php?tab=inbox）

- 新增分页（每页 20 条，`created_at DESC, id DESC` 稳定排序），新增 `count_messages_by_user` / `list_messages_by_user_paged`
- 消息详情（地图名）直接内联展示，不再折叠隐藏
- 新增单条「删除」按钮与「全部删除」按钮（新增 `delete_all_messages`）
- 单条 / 批量删除后保留当前页码，不再跳回第 1 页

### 涉及文件

| 文件 | 操作 |
|------|------|
| `web/src/tables/tasks.php` | 修改（`query_tasks` LEFT JOIN maps 返回 title/size） |
| `web/src/tables/messages.php` | 修改（+`count_messages_by_user` / `list_messages_by_user_paged` / `delete_all_messages`） |
| `web/src/static/js/custom/dashboard.js` | 修改（标题/大小展示、截断 + 悬浮 title、大小时间换位） |
| `web/src/static/js/custom/personal.js` | 修改（批量删除携带页码） |
| `web/src/personal.php` | 修改（收件箱分页 / 内联详情 / 单条+全部删除） |

## 2026-09-05 — dashboard 错误语义化 + 合并并发请求

- `json_error` 增加语义化 HTTP 状态码（401/403/404/409/500/502），前端 `tools.js` 新增 `apiFetch` 统一读取 body 中的 message
- dashboard 任务面板 8 个请求合并为 1（`tasks.php` 按 download/upload 分组返回），监控指标 4 请求合并为 1（Glances `/api/4/all`）
- web/README.md 新增「容器网络使用建议」（容器间优先 compose 网络，避免 host 网络跨边界访问）

## 2026-09-05 — 修复 dashboard 504（Glances 网络 + 会话锁）

- Glances 由 `network_mode: host` 改为 compose 桥接网络，php 经 `http://glances:61208` 访问；原 `host.docker.internal`（host-gateway）解析到已 DOWN 的 docker0、宿主机 ufw 拦截容器→宿主机流量，导致 monitor.php 每次卡 3~10s、连锁拖垮同 session 的 tasks/containers 接口（nginx 504）
- monitor.php / containers.php 鉴权后立即 `session_write_close()`，避免慢请求的会话锁串行阻塞同 session 并发请求

### 升级操作

- 重建 glances 与 php 容器使网络改动生效：`docker compose up -d glances php`

## 2026-08-30 — 开发辅助脚本 + 下载文案 + 安全清理

- 新增 `php-dev.sh` 开发辅助脚本（进入容器 shell / `lint` / `lint-all` / `serve` / `exec`），基于 `l4d2-base-php-cli` 镜像 + `docker-compose.dev.yml` dev profile，不随主 compose 启动
- README 新增「开发辅助（php-dev.sh）」命令说明表
- map_info 下载按钮文案由「COS 加速下载」改为「国内直链下载」
- `.env.example` 去除真实身份信息（品牌域名/邮箱/公司/站点/备案/Steam 群组/SERVER_IP），改用占位符
- `docker.sh clean` 构建缓存清理改为 `--max-used-space=5GB`，避免下次 build 全部重新下载

## 2026-08-30 — COS 登录签名直链 + 清理自定义域名与目录浏览页

- 私有桶下公开 `cos_url` 直链失效（403），改为登录用户点击下载时经 `api/cos_link.php` 现签 COS V5 预签名 URL（新增 `cos_presign_url()`），短时效（默认 60s）近似「单次有效」
- 新增 `COS_PRESIGN_EXPIRE` 环境变量
- 移除已废弃的 `COS_CUSTOM_DOMAIN`（自定义源站域名）与 `cos_index` 目录浏览页（`cos_build_index_html` / `cos_sync_index`）
- `task-daemon` `run_cos_sync` 移除索引页同步步骤

### 升级操作

- `.env`：新增 `COS_PRESIGN_EXPIRE=60`，删除 `COS_CUSTOM_DOMAIN`
- 重建 php 容器注入环境变量：`docker compose up -d php`
- 重建 task-daemon 镜像与容器：`docker compose build task-daemon && docker compose up -d task-daemon`（旧镜像 entrypoint 指向已废弃路径）

### 涉及文件

| 文件 | 操作 |
|------|------|
| `web/src/lib/cos.php` | 新增 `cos_presign_url()`；移除 `COS_CUSTOM_DOMAIN` / `cos_build_index_html` / `cos_sync_index` |
| `web/src/api/cos_link.php` | 新增（登录门槛 + 现签签名直链） |
| `web/src/map_info.php` | 修改（下载按钮登录态渲染 + 点击 fetch） |
| `web/src/bin/task_daemon.php` | 修改（run_cos_sync 去索引页） |
| `web/src/static/html/cos_index.html` | 删除 |
| `docker-compose.yml` | 修改（+COS_PRESIGN_EXPIRE、-COS_CUSTOM_DOMAIN） |
| `.env.example` | 修改（+COS_PRESIGN_EXPIRE、-COS_CUSTOM_DOMAIN） |
| `task-daemon/README.md` | 修改（同步去索引页） |

## 2026-08-30 — 文档整理：web README 归位 + 清理历史决策文档

- `web/docs/README.md` 移至 `web/README.md`，修复全局与内部失效链接
- 删除 `web/docs/` 下 5 个已完成/已废弃的历史文档（架构讨论、审计、优化路线图、架构简化、Model 层），内容已沉淀于 CHANGELOG 与各服务 README
- 待办收敛至 `web/docs/2026-08-29-backlog.md`：COS 直链标记已完成，新增「历史遗留」节合并散落待办

### 升级操作

- 无需操作（纯文档整理）

### 涉及文件

| 文件 | 操作 |
|------|------|
| `web/README.md` | 新增（自 web/docs 移入）+ 修改（去失效链接） |
| `web/docs/2026-07-16-architecture-discussion.md` 等 5 个 | 删除 |
| `web/docs/2026-08-29-backlog.md` | 修改（COS 已完成 + 历史遗留待办） |
| `README.md` | 修改（web/README 链接 + 过时待办） |

## 2026-08-29 — 安全加固（鉴权收敛 / HTTPS / 限流）

### 监控与容器管理鉴权收敛

- **监控接口鉴权（Glances）**：`/monitor-api/*` 原由 nginx 直接代理到 Glances，无鉴权、公网可读宿主机指标。修复：关闭公开代理，新增 `api/monitor.php` 登录校验 + `type` 白名单，服务端转发；php 加 `host.docker.internal`；前端 4 个 fetch 改指代理
- **容器管理鉴权收敛（sidecar）**：`requireAuth()` 空 token 放行、`SIDECAR_TOKEN` 泄露到前端、CORS `*`。修复：空 token 拒绝（503）、新增 `api/containers.php`（admin+CSRF）服务端转发、删除 CORS、nginx 移除 `/manage/*` 公开代理与相关 upstream/extra_hosts/depends_on
- `SIDECAR_TOKEN` 自此仅用于服务间调用（php↔sidecar、task-daemon→php），不进浏览器

### HTTP 强制 HTTPS + 登录信息防泄露

- 域名 `:80` 由明文 serve 改为 301 跳 HTTPS；IP 直访保留公开内容但 login/register 301 到域名 HTTPS
- session cookie 加固：`Secure` + `HttpOnly` + `SameSite=Lax`；`login.php`/`register.php` 强制 HTTPS（非 HTTPS 403）；nginx 传 `fastcgi_param HTTPS $https`

### 收紧请求体上限 + nginx 文档同步

- `client_max_body_size` 2048m → 8m，与 PHP `post_max_size`(8M) 对齐，消除 DoS 攻击面
- `nginx/README.md` 同步路由图、HTTP 行为、body 上限说明

### 内部 token 改走 header

- `SIDECAR_TOKEN` 从 URL `?token=` 改为 `X-Auth-Token` header，避免 token 落入 access log；nginx 显式透传 `HTTP_X_AUTH_TOKEN`；同步 test 脚本与文档

### 接口限流（IP 级防暴力破解）

- nginx `limit_req` 按客户端 IP 限流，弥补 PHP session 级 `rate_limit`「无 Cookie 可绕过」缺陷；`map` 仅敏感端点映射真实 IP，其余空 key 跳过；login/register 5 r/m、check_email 3 r/m，超限 429

### 涉及文件

| 文件 | 操作 |
|------|------|
| `web/src/api/monitor.php` | 新增（登录 + type 白名单转发 Glances） |
| `web/src/api/containers.php` | 新增（admin + CSRF 转发 sidecar） |
| `sidecar/server.php` | 修改（空 token 拒绝 + 删除 CORS） |
| `web/src/dashboard.php` | 修改（移除 token 输出） |
| `web/src/static/js/custom/dashboard.js` | 修改（改走 php 代理，移除 X-Auth-Token） |
| `web/src/etc/bootstrap.php` | 修改（session cookie 加固） |
| `web/src/api/login.php` | 修改（强制 HTTPS） |
| `web/src/api/register.php` | 修改（强制 HTTPS） |
| `web/src/bin/task_daemon.php` | 修改（call_api 改 header） |
| `web/src/api/map_manage.php` | 修改（读 HTTP_X_AUTH_TOKEN） |
| `nginx/data/conf.d/l4d2.conf` | 修改（301 / IP 只读 / limit_req_zone / map） |
| `nginx/data/conf.d/common.inc` | 修改（透传 X-Auth-Token / HTTPS 标志 / body 8m / limit_req） |
| `nginx/README.md` | 修改（过时描述同步） |
| `test/script/healthcheck.sh` / `auto_api.sh` | 修改（curl 改 header） |
| `README.md` / `sidecar/README.md` / `web/docs/README.md` | 修改（架构图、路由表、认证说明） |

## 2026-07-15 — Bug 修复：COS 上传完整性检查缺失

### cos_batch_create_tasks 逻辑漏洞修复

- **问题**：`cos_batch_create_tasks()` 仅查询 `cos_version IS NULL OR cos_version != version` 的地图创建上传任务。对于已标记为"已同步"（`cos_version = version`）的地图，如果 COS 存储桶中对应的 .vpk 文件被意外删除（手动清理、生命周期策略等），系统永远不会为其创建重新上传任务——数据库认为文件还在，实际上已丢失
- **修复**：函数新增完整性检查阶段：
  1. 查询所有已同步的地图（`cos_version IS NOT NULL AND cos_version = version`）
  2. 通过一次 `cos_list_objects()` API 调用获取 COS 上所有 .vpk 文件，构建查找集合（避免对每张地图发 HEAD 请求）
  3. 交叉比对：COS 上缺失的已同步地图自动加入上传队列
- 返回值新增 `recovered` 字段（从 COS 缺失恢复的地图数）
- `run_cos_sync()` 日志消息在 recovered > 0 时显示恢复数量
- `cos_batch_upload()` 兼容封装同步透传 `recovered`

### 涉及文件

| 文件 | 操作 |
|------|------|
| `web/src/lib/upload.php` | 修改（`cos_batch_create_tasks` +完整性检查；`cos_batch_upload` 透传 recovered） |
| `web/src/task_daemon.php` | 修改（`run_cos_sync` 日志消息含 recovered） |

## 2026-07-12 — Bug 修复：include 路径 + DB 重连 + 时区

### downloader.php include 路径修复

- **问题**：`api/lib/downloader.php` 使用 `include_once 'tools.php'` 裸文件名，PHP 在 CWD 和 include_path 中查找，找不到 `/var/www/html/api/tools.php`，输出 Warning
- **修复**：改为 `include_once __DIR__ . '/../tools.php'`，相对于文件自身目录解析

### conn_db() 死连接缓存修复

- **问题**：`conn_db()` 内部 `static $pdo` 在 MySQL 重启后仍返回旧 PDO 对象（`instanceof PDO` 为 true），`safe_execute()` 和 `ensure_db_alive()` 的重连逻辑永远拿到死连接，无法恢复
- **修复**：移除 `static` 缓存和 `ATTR_PERSISTENT`，每次调用创建新连接，让重连逻辑真正生效

### 容器时区修正

- **问题**：PHP 容器使用 UTC（差 8 小时），`date.timezone` 未设置，日志时间与宿主机不一致
- **修复**：
  - `base-php/Dockerfile.cli` + `base-php/Dockerfile.fpm`：安装 `tzdata` + `ARG TZ` → `ENV TZ` 烘焙进镜像 + 写入 `/usr/local/etc/php/conf.d/timezone.ini`
  - `nginx/Dockerfile`：`ARG TZ` → `ENV TZ` 烘焙进镜像
  - `docker-compose.yml`：`base-php-*` 和 `nginx` 的 `build.args` 传入 `TZ: ${TZ:-Asia/Shanghai}`，从 `.env` 读取
  - `mysql` 保留运行时 `TZ`（外部镜像无法修改 Dockerfile）
  - 游戏服和 glances 不注 TZ（无需关注时区）

### 涉及文件

| 文件 | 操作 |
|------|------|
| `web/src/api/lib/downloader.php` | 修改（include 路径） |
| `web/src/api/tools.php` | 修改（conn_db 去静态缓存） |
| `base-php/Dockerfile.cli` | 修改（+tzdata +ARG TZ +ENV TZ +php timezone.ini） |
| `base-php/Dockerfile.fpm` | 修改（+tzdata +ARG TZ +ENV TZ +php timezone.ini） |
| `nginx/Dockerfile` | 修改（+ARG TZ +ENV TZ） |
| `docker-compose.yml` | 修改（base-php/nginx build.args +TZ；mysql runtime TZ；其余容器去 TZ） |
| `.env.example` | 修改（+TZ 变量） |
| `CHANGELOG.md` | 修改 |

## 2026-07-11 — 服务重命名 + COS 同步架构修正

### `downloader` 服务 → `task-daemon`

- 服务/容器/镜像名从 `downloader` / `l4d2-downloader` 改为 `task-daemon` / `l4d2-task-daemon`，与 `task_daemon.php` 语义一致
- 构建目录 `downloader/` → `task-daemon/`，`entrypoint.sh` 内系统用户同步更新
- 全项目引用同步更新：`docker-compose.yml`、`sidecar/server.php`、`sidecar/Dockerfile`、`base-php/Dockerfile.cli`、`README.md`、`test/script/*.sh`、`map_manage.php` 注释

### COS 同步架构修正

- **问题**：上次重构将 COS 同步从 daemon 移到了 Web API（`trigger_cos_sync`），但 php 容器没有 addons 卷挂载，`cos_batch_upload()` 的 `file_exists()` 全部返回 false
- **修复**：COS 同步回归 task-daemon 容器本地执行，php 容器不再挂载 addons
  - `task_daemon.php` 新增 `run_cos_sync()` + `process_manual_triggers()`，主循环检查触发文件
  - `daily_maintenance()` 的 COS 同步改为本地调用，不再走 HTTP API
  - `map_manage.php` 的 `trigger_cos_sync` action 改为写入触发文件（`LOG_DIR/.trigger_cos_sync`）
  - task-daemon 容器恢复 COS 环境变量 + 新增 `./web/src/static:/var/www/html/static:ro`（供 `cos_sync_index` 读模板）
- addons 卷只有 task-daemon 挂载（rw，下载 vpk 需要写），php 容器不再挂载

### 涉及文件

| 文件 | 操作 |
|------|------|
| `docker-compose.yml` | 修改（php 去 addons 挂载；task-daemon +COS 变量 +static 挂载；downloader → task-daemon） |
| `task-daemon/` | 重命名（原 `downloader/`） |
| `task-daemon/entrypoint.sh` | 修改（系统用户名） |
| `web/src/task_daemon.php` | 修改（+run_cos_sync、+process_manual_triggers、daily_maintenance 改为本地调用） |
| `web/src/api/map_manage.php` | 修改（trigger_cos_sync 改为写入触发文件） |
| `web/src/static/js/custom/map_manage.js` | 修改（triggerCosSync UI 文案适配异步） |
| `sidecar/server.php` | 修改（容器白名单） |
| `sidecar/Dockerfile` | 修改（默认白名单） |
| `base-php/Dockerfile.cli` | 修改（注释） |
| `README.md` | 修改（服务名 + 架构图 + 环境变量表 + 目录结构） |
| `test/script/healthcheck.sh` | 修改（容器名引用） |
| `test/script/auto_cos.sh` | 修改（容器名引用） |
| `test/script/auto_logrotate.sh` | 修改（提示文字） |
| `CHANGELOG.md` | 修改 |

## 2026-07-11 — COS 目录浏览 + 任务守护重构 + 管理端手动触发

### COS 静态网站文件浏览器

- 新增 `static/html/cos_index.html` — JS 动态调用 ListBucket XML API，面包屑导航 + 目录展开
- 配置通过 `{{COS_BUCKET}}` 等占位符在生成时从 `.env` 动态注入
- 桶静态网站「默认首页」设为 `index.html`，访问 `http://map.tunarund.top/` 即可浏览全部文件
- **⚠️ 自定义域名源站类型必须是「静态网站源站」**（非 CDN），否则 `/` 不重定向到 `index.html`

### COS 控制台配置（必需）

- **自定义域名** → 源站类型：静态网站源站 | **CORS** → Origin: `*`, Methods: `GET,HEAD` | **Bucket Policy** → 允许匿名 `GetBucket`

### COS 逻辑收敛 + 孤儿清理

- `cos_client.php` 新增 5 个函数：`cos_batch_upload` / `cos_delete_object` / `cos_build_index_html` / `cos_sync_index` / `cos_cleanup_orphans`
- 每日维护自动清理桶中存在但 DB 中 `status != active` 的孤儿 .vpk 文件

### `downloader_daemon.php` → `task_daemon.php`

- 重命名（功能不限于下载），日志路径同步更新
- daemon 不再直接 include `cos_client.php`，改为通过 `call_api()` 调 `map_manage.php?action=trigger_update_all|trigger_cos_sync`
- downloader 容器移除 COS 环境变量和模板挂载，职责缩到下载 + HTTP 编排
- COS 凭证转移到 PHP 容器（`trigger_cos_sync` 执行端）

### 管理端手动触发按钮

- `personal.php?tab=map_manage` 新增「检查更新」+「COS 同步」两个按钮
- `map_manage.php` 新增 `trigger_update_all` / `trigger_cos_sync` action

### 文件命名规范化

- JS/HTML 文件名统一为下划线：`cos-index`→`cos_index`，`map-manage`→`map_manage`，`map-request`→`map_request`

### 涉及文件

| 文件 | 操作 |
|------|------|
| `web/src/static/html/cos_index.html` | 新增（模板，含 `{{...}}` 占位符） |
| `web/src/static/js/custom/map_manage.js` | 重命名 + trigger 处理函数 |
| `web/src/static/js/custom/map_request.js` | 重命名 |
| `web/src/api/cos_client.php` | 重写（+5 函数，默认模板路径修正） |
| `web/src/api/map_manage.php` | 修改（+trigger_update_all / trigger_cos_sync） |
| `web/src/task_daemon.php` | 新增（纯 HTTP 编排） |
| `web/src/downloader_daemon.php` | 删除 |
| `web/src/personal.php` | 修改（手动触发按钮 + JS 引用更新） |
| `docker-compose.yml` | 修改（php +COS 变量，downloader -COS/-模板挂载） |
| `downloader/entrypoint.sh` | 修改（daemon 路径） |
| `test/script/healthcheck.sh` | 修改（daemon 路径） |
| `test/script/auto_logrotate.sh` | 修改（日志路径） |
| `CHANGELOG.md` | 重写 |

## 2026-07-11 — 前端修复 + 下载器断点续传 + COS 签名修复

### jsdelivr CDN → 本地

- `dashboard.php` 唯一一处 jsdelivr 引用（Chart.js v4.4.8）改为本地 `static/js/chart.umd.min.js`（~206KB），离线可用
- 后续添加的 JS 库统一放入 `static/js/`，不再依赖外部 CDN

### 下载器断点续传

- `api/downloader.php` 的 `download_with_progress()` 重写：
  - 首次下载中断 → 文件保留，下次重试从断点继续（`CURLOPT_RESUME_FROM`）
  - 打开模式：有残留文件 → `ab`（追加），无 → `wb`
  - 进度回调补偿续传偏移量，数据库 `downloaded_bytes` 正确反映实际进度
  - 服务器不支持 Range（返回 200 而非 206）→ 自动删文件从头下载，不消耗重试次数
  - 日志记录每次重试的已保留字节数

### 网络带宽显示修正

- `static/js/custom/dashboard.js`：`↓下载` 与 `↑上传` 的 RX/TX 数据源对调
  - 原来：下载=RX（收），上传=TX（发）→ 与服务器视角相反
  - 现在：下载=TX（发，客户端从服务器下载），上传=RX（收，客户端上传到服务器）

### 地图申请修复

- **申请成功无批准按钮**：`map-request.js` 的 `save_button()` 补上批准按钮（`data-action="approve"`），与 `loadMapRequests()` 一致
- **删除按钮跳页**：所有 `<button>` 在 `<form>` 内默认 `type="submit"` → 加 `type="button"` 阻止表单提交
- **删除后重复申请 1062 错误**：`api/map_request.php` 的 `delete_request()` 两个 bug：
  - `$stmt->fetch() == 0` → `$stmt->fetchColumn() == 0`（PHP 8 中数组 `== 0` 永为 false）
  - 管理员删除补上 `map_request_users` 清理，防止孤儿记录

### 创意工坊链接

- `personal.php` 地图申请页添加工坊跳转 + 类型提示（仅地图、无需材质/音频）

### 腾讯 COS 签名修复

- `api/cos_client.php` 的 `cos_generate_auth()` 从 `q-sign-algorithm=sha1` 格式改为 AWS S3 V2 格式（`AWS id:sig`），与 Tencent COS 兼容
- 修复 Header 查找 bug：小写 key 无法匹配混合大小写原始 key
- 新增 `cos_list_objects()` — GET Bucket 列出对象，支持 prefix/delimiter 目录分组
- COS 上传路径去掉 `l4d2-maps/` 前缀，文件直放 bucket 根目录
- 配合 COS 静态网站托管 + 目录浏览，`http://map.tunarund.top/` 即可浏览全部文件

### 涉及文件

| 文件 | 操作 |
|------|------|
| `web/src/dashboard.php` | 修改（jsdelivr → 本地 Chart.js） |
| `web/src/static/js/chart.umd.min.js` | 新增（Chart.js v4.4.8） |
| `web/src/static/js/custom/dashboard.js` | 修改（下载/上传带宽对调） |
| `web/src/static/js/custom/map-request.js` | 修改（批准按钮 + type=button） |
| `web/src/api/downloader.php` | 修改（断点续传） |
| `web/src/api/map_request.php` | 修改（fetchColumn + 管理员清理绑定） |
| `web/src/personal.php` | 修改（创意工坊链接 + 提示） |
| `web/src/api/cos_client.php` | 修改（AWS V2 签名 + list_objects + 独立 error 函数） |
| `web/src/downloader_daemon.php` | 修改（COS key 去 l4d2-maps 前缀） |
| `CHANGELOG.md` | 修改 |

## 2026-07-11 — Docker 网络加速 + HTTPS 开箱即用

### Docker 基础设施优化

- `daemon.json` 新增 `max-concurrent-downloads: 10` / `max-concurrent-uploads: 5`
- 镜像源列表扩充：新增 `docker.1panel.live` / `dockerproxy.link` / `free.hubfast.cn` / `registry.cyou` 等已验证源

### 涉及文件

| 文件 | 操作 |
|------|------|
| `nginx/data/certs/privkey.pem` | 新增（自签名私钥） |
| `nginx/data/certs/fullchain.pem` | 新增（自签名证书） |
| `.gitignore` | 修改（放开 pem 证书文件） |
| `docker.sh` | 修改（更新镜像源列表 + 并发参数） |
| `CHANGELOG.md` | 修改 |

## 2026-07-10 — 腾讯 COS 集成 + 守护进程重构 + 日志轮转

### 腾讯云 COS 对象存储集成

- 新增 `web/src/api/cos_client.php` — 原生 COS 客户端（HMAC-SHA1 签名，零 SDK 依赖）
  - `cos_upload_file()` 流式 PUT 上传（`CURLOPT_INFILE`），大文件不占内存，支持 3 次退避重试
  - `cos_head_object()` HEAD 请求检查对象是否存在
  - 配置通过环境变量注入：`COS_SECRET_ID` / `COS_SECRET_KEY` / `COS_BUCKET` / `COS_REGION`
- **延迟批量上传** — 下载完成后不立即上传（避免阻塞后续任务），统一在每日凌晨 3 点地图更新检查之后批量处理
- **版本比较防重复** — `maps` 表新增 `cos_url` / `cos_version` 字段，`cos_version != version` 才重新上传
- **前端展示** — `map_info.php` 地图详情页增加双 CDN 下载按钮：
  - 腾讯CDN（蓝色按钮）— COS 公网直链
  - SteamCDN（绿色按钮）— Steam Akamai 直链
- 新增 `mysql/initdb/02-cos.sql` 增量迁移脚本

### 守护进程重构

- **修复每日更新 auth bug**：`map_manage.php?action=update_all` 因 Docker 内网 IP 不匹配本地白名单返回 "请先登录"
  - `map_manage.php` 新增 `token` 参数认证通道（复用 `SIDECAR_TOKEN`），内部调用跳过登录检查
  - downloader 容器注入 `SIDECAR_TOKEN` 环境变量
- **主循环精简**：从 ~90 行降至 6 行，提取三个职责清晰的函数：
  - `ensure_db_alive()` — DB 断连重试
  - `daily_maintenance()` — 每日地图更新 + COS 批量上传
  - `process_next_download_task()` — 取任务 → 下载 → 回调
- 有任务时立即处理下一个不再 sleep，消除 5 秒空转

### 日志按日轮转

- `tools.php` 新增 `daily_log_path()` — 自动生成 `{应用名}/YYYY/MM/DD.log` 路径
- `add_log()` 内部调用，调用方无需感知轮转逻辑
- 零外部依赖，容器内外一致运行

### 健康检查增强

- `healthcheck.sh` 重写，新增检查分组：
  - **下载器** — 容器状态、全部 PHP 文件语法、cos_client 函数可加载、COS 配置状态、日志目录
  - **每日更新** — `map_manage.php` token 认证验证
  - **数据库** — `maps` 表 `cos_url` / `cos_version` 列存在性检查
- 修复旧脚本 bug：容器名 `web` → `php`，工作目录 `..` → `.`
- 新增 `hash` 扩展检查（COS HMAC-SHA1 签名依赖）

### README 更新

- 补充 COS、日志轮转、健康检查到核心设计
- 环境变量表新增 COS 配置项，`SIDECAR_TOKEN` 服务增加 downloader
- 目录结构新增 `CHANGELOG.md`
- 修正"wget 下载"为"curl 下载"

### 新增环境变量

| 变量 | 服务 | 说明 |
|------|------|------|
| `COS_SECRET_ID` / `COS_SECRET_KEY` | downloader | 腾讯云 API 密钥 |
| `COS_BUCKET` | downloader | COS 存储桶名称（含 APPID） |
| `COS_REGION` | downloader | 存储桶地域，默认 `ap-guangzhou` |
| `COS_CUSTOM_DOMAIN` | downloader | 可选：CDN 加速域名 |
| `SIDECAR_TOKEN` | downloader | 内部 API 调用认证（已有变量，新增到 downloader 容器） |

### 数据库迁移

存量环境执行：
```bash
docker exec -i l4d2-mysql mysql -u steam -p steam < mysql/initdb/02-cos.sql
```

### 涉及文件

| 文件 | 操作 |
|------|------|
| `web/src/api/cos_client.php` | 新增 |
| `web/src/api/downloader.php` | 修改（引入 COS 模块） |
| `web/src/api/download_tasks.php` | 修改（查询含 cos_url，后还原至 maps 表方案） |
| `web/src/api/map_manage.php` | 修改（新增 token 认证通道） |
| `web/src/api/tools.php` | 修改（新增 `daily_log_path()`，`add_log()` 改为按日轮转） |
| `web/src/downloader_daemon.php` | 重写（提取函数 + COS 批量上传 + 日志轮转） |
| `web/src/map_info.php` | 修改（双 CDN 下载按钮） |
| `mysql/initdb/01-steam.sql` | 修改（maps 表新增 `cos_url` / `cos_version`） |
| `mysql/initdb/02-cos.sql` | 新增（增量迁移脚本） |
| `.env.example` | 重写（🔴必改→🟡环境→🟢可选，按应用分组，补充获取方式） |
| `docker-compose.yml` | 修改（downloader 容器新增 COS 及 SIDECAR_TOKEN 环境变量） |
| `healthcheck.sh` | 重写（新增下载器、COS、日志轮转、token 认证检查） |
| `README.md` | 修改（更新过时描述，补充 COS 环境变量） |
| `CHANGELOG.md` | 新增 |

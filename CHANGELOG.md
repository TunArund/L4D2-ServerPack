# CHANGELOG

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

# 架构简化方案 — 2026-07-17

> 讨论记录：[2026-07-17-model-layer.md](2026-07-17-model-layer.md) 的后续——从"引入 Model 层"到"做减法"。

## 架构模式

项目采用 Martin Fowler *Patterns of Enterprise Application Architecture* 中的标准三层组合：

```
表现层       Page Controller     ← web/src/*.php（一个文件一个页面入口）
业务层       Transaction Script  ← web/src/api/*.php（一个 action 一套过程式逻辑）
数据源层     Row Data Gateway    ← web/src/tables/*.php（一个文件一张表，纯函数）
基础设施                         ← web/src/lib/*.php（DB 连接、日志、认证、外部 API 驱动）
```

依赖方向单向：API/页面 → tables/ → lib/。lib/ 不依赖上层。

## 目录结构

```
web/src/
├── etc/                          ← 配置与启动（从根目录移入）
│   ├── config.php                ← 常量定义（DB、路径、环境）
│   └── bootstrap.php             ← HTTP 前置加载（nginx auto_prepend_file）
│
├── bin/                          ← CLI 入口（从根目录和 lib/ 移入）
│   ├── task_daemon.php           ← 下载/上传守护进程
│   └── debug.php                 ← 开发调试工具
│
├── lib/                          ← 基础设施 + 外部驱动
│   ├── core.php                  ← DB 连接、日志、array/json helper
│   ├── db.php                    ← PDO 辅助（exec_stmt + db_fetch_* 系列）
│   ├── auth.php                  ← session / CSRF / 限流
│   ├── steam.php                 ← Steam Workshop API 封装
│   ├── cos.php                   ← 腾讯 COS 上传驱动（从 upload.php 改名）
│   ├── ses.php                   ← 腾讯 SES 发信驱动（从 email.php 改名）
│   └── download.php              ← curl 流式下载驱动
│
├── tables/                       ← 数据库操作函数（从 lib/models/ 移出，改名）
│   ├── users.php                 ← find_user_by_id(), insert_user(), ...
│   ├── maps.php                  ← find_map_by_id(), list_maps(), insert_map(), ...
│   ├── tasks.php                 ← query_tasks(), insert_task(), update_task_status(), ...
│   ├── map_requests.php          ← find_request_by_id(), list_requests(), ...
│   ├── map_request_users.php     ← bind_request_user(), get_user_ids_by_request(), ...
│   ├── messages.php              ← count_unread_messages(), list_messages_by_user(), ...
│   ├── comments.php              ← list_comments_by_map(), insert_comment(), ...
│   └── emails.php                ← find_email(), upsert_email()
│
├── api/                          ← HTTP API 端点（Transaction Script）
│   ├── login.php
│   ├── register.php
│   ├── check_email.php
│   ├── logout.php
│   ├── map_manage.php            ← ?action= 内部分发
│   ├── map_request.php           ← ?action= 内部分发
│   ├── tasks.php
│   ├── messages.php
│   └── delete_comment.php
│
├── static/                       ← 前端静态资源
├── index.php                     ← 首页
├── dashboard.php                 ← 仪表盘
├── billboard.php                 ← 地图列表
├── map_info.php                  ← 地图详情
├── personal.php                  ← 个人中心
└── navbar.php                    ← 共享导航栏组件
```

## 关键变动

### 1. 删除 BaseModel

**现状**：`BaseModel` 的 125 行代码中，3 个方法（`pdo`、`execute`、`safeExecute`）是纯改名——分别等于 `conn_db()`、`exec_stmt()`、`safe_execute()`。其余 5 个方法（`fetchAll`、`fetchOne`、`fetchColumn`、`insertAndGetId`、`executeWrite`）是 1 行 PDO 调用 + `array_success` 包装。

**做法**：把真正复用的 5 个 helper 提取为 `db.php` 里的普通函数：

```php
// lib/db.php — 新增
function db_fetch_all(string $sql, array $params = []): array { ... }
function db_fetch_one(string $sql, array $params = []): array { ... }
function db_fetch_column(string $sql, array $params = [], int $col = 0): array { ... }
function db_execute_write(string $sql, array $params = []): array { ... }
function db_insert(string $sql, array $params = []): array { ... }
```

调用链从 6 层变为 2 层：

```
之前: UserModel::findById → fetchOne → query → pdo → execute → conn_db + exec_stmt → PDO
之后: find_user_by_id → db_fetch_one → conn_db + exec_stmt → PDO
```

### 2. models/ → tables/，类 → 函数

**现状**：8 个 class（`XxxModel extends BaseModel`），全部只有 static 方法。`extends BaseModel` 是编译时绑定，增加了耦合但没有复用价值。

**做法**：每个文件改为普通函数，按 `verb_noun` 风格命名：

```php
// tables/users.php
function find_user_by_id(int $id): array { return db_fetch_one('SELECT ...'); }
function find_user_by_username(string $name): array { return db_fetch_one('SELECT ...'); }
function insert_user(string $username, ...): array { return db_insert('INSERT ...'); }
function user_email_exists(string $email): array { ... }

// 调用方
$result = find_user_by_id($id);
$result = list_maps(['limit' => 10, 'offset' => 0]);
```

命名与项目现有风格（`check_login`、`add_log`、`fetch_steam_item_by_api`）一致，不引入新的命名体系。

### 3. 删除 lib/map.php

**现状**：9 个函数中，1 个是死代码（`isDownloadLinkValid`），5 个只有 1 个调用者，真正复用的只有 3 个（`uninstall_map`、`update_maps`、`build_map_request`）。

**做法**：

| 函数 | 处理 |
|------|------|
| `isDownloadLinkValid()` | 删除（无调用者） |
| `delete_map()` | 迁回 `api/map_manage.php` |
| `delete_request()` | 迁回 `api/map_request.php` |
| `add_request()` | 迁回 `api/map_request.php` |
| `approve_request()` | 迁回 `api/map_request.php` |
| `apply_map_update()` | 迁回 `api/map_manage.php`（update_all 内部） |
| `uninstall_map()` | 保留，移至 tables/maps.php（文件操作 + 状态更新） |
| `update_maps()` + `build_map_request()` | 保留，移至 tables/maps.php |

原 `lib/map.php` 文件删除。

### 4. lib/download.php 精简

**现状**：3 个函数全部只被 `task_daemon.php` 调用。

- `download_with_progress()` — curl 流式下载，属于外部驱动，**保留在 lib/download.php**
- `download_success_callback()` — 业务编排（更新状态 + 发通知），**迁回 task_daemon.php**
- `download_fail_callback()` — 同上，**迁回 task_daemon.php**

### 5. 驱动文件改名

| 旧名 | 新名 | 原因 |
|------|------|------|
| `lib/upload.php` | `lib/cos.php` | `upload` 太泛；与 tables/ 无冲突 |
| `lib/email.php` | `lib/ses.php` | 和 `tables/emails.php` 同名会混淆；`ses` 明确指腾讯 SES API |

### 6. etc/ — 配置和启动

`config.php` 新增常量：

```php
define('ETC_DIR', SRC_DIR . 'etc/');
define('TABLES_DIR', SRC_DIR . 'tables/');
define('BIN_DIR', SRC_DIR . 'bin/');
```

nginx `auto_prepend_file` 从 `web/src/bootstrap.php` 改为 `web/src/etc/bootstrap.php`。

bootstrap.php 内 `require_once __DIR__ . '/config.php'` 无需修改（两者移至同目录）。

### 7. 清理化石 $pdo 参数

以下函数签名中的 `$pdo` 参数删除（函数体内部已使用 tables/ 函数，不再需要传入）：

- `uninstall_map($pdo, $id)` → `uninstall_map($id)`
- `delete_map($pdo, $id)` → `delete_map($id)`
- `add_request($pdo, $user_id, $steam_id)` → `add_request($user_id, $steam_id)`
- `approve_request($pdo, $request_id)` → `approve_request($request_id)`
- `update_maps($pdo, $map_rows)` → `update_maps($map_rows)`
- `billboard.php` 的 `get_map_count($pdo, ...)` 和 `get_map_info($pdo, ...)` → 删除（直接调 tables/ 函数）

## lib/ 判据

一个函数是否应该放在 `lib/` 下：

| 条件 | 示例 |
|------|------|
| 被多个入口文件调用 | `conn_db()`, `check_login()`, `csrf_token()` |
| 封装外部 I/O 协议 | `cos_upload_file()`, `fetch_steam_item_by_api()`, `download_with_progress()` |
| 纯数据访问函数（多入口查同一张表） | `find_map_by_id()`, `list_users()`, `query_tasks()` |

| 不放 lib/ | 原因 |
|-----------|------|
| 只被一个 API action 调用的业务编排 | `add_request()`, `approve_request()` |
| 只被 daemon 调用的回调 | `download_success_callback()` |
| 3 行的薄包装器 | `get_map_count()`, `get_map_info()` |
| 不被任何地方调用的 | `isDownloadLinkValid()` |

## 删除清单

| 删除项 | 行数 | 原因 |
|--------|------|------|
| `lib/models/BaseModel.php` | 125 | 纯转手抽象层 |
| `lib/map.php` | 250 | 函数迁回调用处或 tables/ |
| `lib/task.php` | 25 | 已删除（上次重构） |
| `lib/upload.php` | — | 重命名为 cos.php |
| `lib/email.php` | — | 重命名为 ses.php |
| `billboard.php` 内 `get_map_count` + `get_map_info` | 15 | 薄包装器，直接调 tables/ |
| `register.php` 内 `register()` | 5 | 薄包装器，直接调 `insert_user()` |
| 各函数签名中的 `$pdo` | — | 化石参数 |

## 不变项

| 文件 | 说明 |
|------|------|
| `lib/core.php` | 基础设施，保持不变 |
| `lib/db.php` | 新增 5 个 helper 函数，`exec_stmt`/`safe_execute` 不变 |
| `lib/auth.php` | 保持不变 |
| `lib/steam.php` | 保持不变 |
| `lib/download.php` | 保留 `download_with_progress()`，删除回调函数 |
| `api/*.php` 的 switch-case 分发结构 | 保持不变 |
| `static/` | 保持不变 |
| 页面文件（`index.php`, `dashboard.php` 等） | 保持不变 |

## 架构原则

1. **文件路径即路由** — Page Controller 模式，一个 PHP 文件一个入口，不用集中式路由器
2. **一个表一个文件** — tables/ 下每个文件对应一张数据库表，包含该表的所有操作函数
3. **一入口一过程** — 每个 API action 是一个 Transaction Script，自包含：验证输入 → 调用 tables/ → 返回响应
4. **不放 lib 除非复用** — 一个函数只有被 ≥2 个入口调用，才考虑提取到 lib/
5. **lib/ 只放基础设施和外部驱动** — 不放业务逻辑、不放单入口编排函数
6. **函数优于类** — 无状态操作使用普通函数，类仅用于需要实例化的组件（如页面内的 HTML 组件）
7. **命名统一** — 全程 `verb_noun` 小写下划线，与项目现有风格一致

# Model 层抽象 — 2026-07-17

> 将所有散落在 API 端点、页面文件和 lib 函数中的原始 PDO 操作，按实体抽象为统一的 Model 层。

## 动机

重构前，原始 PDO 调用分布在 **17 个文件** 中——同一张 `maps` 表的查询逻辑出现在 `lib/map.php`、`api/map_manage.php`、`billboard.php`、`map_info.php`、`lib/download.php`、`lib/upload.php` 等多个位置，SQL 语句重复、缺乏类型约束、数据访问与业务逻辑紧密耦合。

## 设计决策

| 维度 | 选择 | 理由 |
|------|------|------|
| 模式 | **Repository 风格**（静态方法） | 与现有 `array_error/success` 返回模式一致；Model 内部调用 `conn_db()`，无需传递 `$pdo` |
| 数据结构 | 关联数组（无类型属性类） | 保持与现有代码库一致的约定；无 Composer / autoloader |
| 文件组织 | 一个实体一个文件，位于 `lib/models/` | 清晰的职责分离；简单的 `require_once` 引用 |
| 关系处理 | 每个 Model 提供显式关联查询方法 | 避免 ORM 复杂性；显式优于隐式 |
| 迁移策略 | 旧函数保留签名，内部委托给 Model | 兼容旧调用方；零破坏性变更 |
| SQL 防注入 | 动态 ORDER BY / LIMIT 使用白名单校验 | 继承现有 `list_map()` / `list_request()` 模式 |

## 文件结构

```
web/src/lib/models/
├── BaseModel.php              ← 抽象基类（PDO 访问 + 查询辅助）
├── UserModel.php              ← users 表
├── MessageModel.php           ← messages 表
├── CommentModel.php           ← comments 表
├── EmailModel.php             ← emails 表
├── MapModel.php               ← maps 表
├── TaskModel.php              ← tasks 表
├── MapRequestModel.php        ← map_requests 表
└── MapRequestUserModel.php    ← map_request_users 关联表
```

## BaseModel 设计

```php
abstract class BaseModel
{
    protected static function pdo(): PDO                  // conn_db() 单例
    protected static function execute($stmt, ...$params)  // 委托 exec_stmt()
    protected static function safeExecute($sql, $params)  // 委托 safe_execute()（daemon 长连接）
    protected static function query($sql, $params)        // prepare + execute
    protected static function fetchAll($sql, $params)     // 查询多行 → array_success(rows)
    protected static function fetchOne($sql, $params)     // 查询单行 → array_success(row|null)
    protected static function fetchColumn($sql, $params)  // 查询标量 → array_success(value)
    protected static function insertAndGetId($sql, $params) // INSERT → array_success(lastInsertId)
    protected static function executeWrite($sql, $params) // UPDATE/DELETE → array_success(rowCount)
    protected static function validateOrderBy($col, $allowed) // 白名单校验
    protected static function validateOrder($order)       // ASC/DESC 校验
}
```

### 设计规则

1. **所有方法为 `public static`** — 无需实例化，内部调用 `conn_db()`
2. **返回简单类型** — `?array`、`array`、`int`、`bool`。不使用 `array_error/success` 模式（保留给 service/business 层）
3. **无业务逻辑** — Model 仅做纯数据访问。业务逻辑（Steam API 调用、文件操作、校验）留在 `lib/map.php` 等
4. **异常处理** — Model 在内部捕获 `PDOException` 并通过 `array_error()` 返回；调用方可据此包装
5. **动态 SQL 安全** — ORDER BY / LIMIT / OFFSET 在 Model 内部白名单校验

## 实体 ↔ Model 映射

### UserModel — users 表

| 方法 | 替代的原始 PDO |
|------|---------------|
| `findById($id)` | `personal.php`: `SELECT ... FROM users WHERE id = ?` |
| `findByUsername($name)` | `login.php`: `SELECT * FROM users WHERE username = :username` |
| `insert($username, $email, $hashpass, $role)` | `register.php`: `INSERT INTO users (...)` |
| `emailExists($email)` | `check_email.php`: `SELECT count(*) FROM users WHERE email=?` |

### MapModel — maps 表

| 方法 | 替代位置 |
|------|---------|
| `findById($id)` | `map_info.php`、`approve_request()` |
| `findBySteamId($id)` | `fetch_db_item()` |
| `findWithDiskSafe($id)` | `uninstall_map()` |
| `list($opts)` | `list_map()` |
| `listWithPreview($opts)` | `get_map_info()` in `billboard.php` |
| `count($search)` | `count_map()` + `get_map_count()` |
| `allActive()` | COS 同步查询 |
| `allPendingCosSync()` | `cos_batch_create_tasks()` |
| `allExceptUpdating()` | `update_all` |
| `findForUpdateByIds($ids)` | `map_manage.php` update action |
| `insert($data)` | `insert_map()` |
| `update($id, $data)` | `update_map_info()` |
| `updateStatus($id, $status)` | 多处散落的 `UPDATE maps SET status=?` |
| `updateVersionMeta($id, $info)` | `apply_map_update()` |
| `updateCosInfo($id, $url, $ver)` | `process_upload_task()` |
| `delete($id)` | `delete_map()` |
| `findInMapsOrRequests($steamId)` | `fetch_db_item()`（跨表查询） |

### TaskModel — tasks 表

| 方法 | 说明 |
|------|------|
| `query($type, $status, $limit)` | 替代 `query_tasks()` |
| `insert($data)` | 替代 `add_download_task()` + COS 任务创建 |
| `updateStatus($id, $status)` | 替代多处 `UPDATE tasks SET status=?` |
| `updateProgress($id, $processed, $total)` | 替代下载/上传进度回调中的直接 PDO |
| `existsDuplicate($mapId, $type)` | 替代重复任务检查 |
| `fetchNextDownloadWaiting()` 等 4 个 daemon 方法 | 替代 `fetch_next_task()` 中的 4 次 `$pdo->query()` |

### MapRequestModel — map_requests 表

| 方法 | 替代的原始 PDO |
|------|---------------|
| `findById($id)` | `fetch_map_request()` |
| `findBySteamId($id, $status)` | `fetch_map_request()` |
| `list($opts)` | `list_request()`（管理员视图） |
| `listByUser($userId, $opts)` | `list_request()`（用户视图） |
| `count()` | `count_request()` |
| `insert($data)` | `insert_map_request()` |
| `updateStatus($id, $status)` | `approve_request()` |
| `delete($id)` | `delete_request()` |
| `deleteBySteamId($steamId)` | `delete_all_request()` |

### MapRequestUserModel — map_request_users 关联表

| 方法 | 替代的原始 PDO |
|------|---------------|
| `bind($requestId, $userId)` | `bind_user_to_request()` |
| `getUserIdsByRequest($requestId)` | `fetch_users_by_request()` |
| `getUserIdsBySteamId($steamId)` | `fetch_related_users()` |
| `deleteByRequest($requestId)` | `delete_request()` (admin) |
| `deleteByUserAndRequest($requestId, $userId)` | `delete_request()` (user) |
| `countByRequest($requestId)` | `delete_request()` 中的残留检查 |

### MessageModel — messages 表

| 方法 | 替代位置 |
|------|---------|
| `unreadCount($userId)` | `messages.php` |
| `listUnread($userId, $limit)` | `messages.php` |
| `listByUser($userId)` | `personal.php` `printInbox()` |
| `markRead($id, $userId)` | `personal.php` |
| `markAllRead($userId)` | `personal.php` |
| `delete($id, $userId)` | `personal.php` |
| `deleteMany($ids, $userId)` | `personal.php` |
| `broadcast($userIds, $title, $msg)` | `broadcast_message()` in `core.php` |

### CommentModel — comments 表

| 方法 | 替代位置 |
|------|---------|
| `listByMap($mapId)` | `map_info.php` `print_comments()` |
| `insert($mapId, $userId, $comment)` | `map_info.php` POST |
| `delete($id)` | `delete_comment.php` |

### EmailModel — emails 表

| 方法 | 替代位置 |
|------|---------|
| `findByEmail($email)` | `register.php` + `check_email.php` |
| `upsert($email, $code, $expire)` | `updatedb()` in `check_email.php` |

## 迁移影响

### 变更统计

| 类别 | 文件数 | 行数变化 |
|------|--------|---------|
| 新增 Model | 9 | +1276 |
| 删除文件 | 1 (`lib/task.php`) | -25 |
| 重构 lib/（精简薄包装器） | 4 | -278 |
| 重构 api/ | 7 | -64 |
| 重构 page/ | 3 | -62 |
| 重构 daemon + bootstrap | 2 | +20 |
| 文档 | 2 | — |

### 架构简化

- **删除 `lib/task.php`** — 只有一个函数，API 直接调用 `TaskModel::query()`
- **`lib/map.php` 从 21 个函数精简为 9 个** — 删除了 12 个纯委托包装器（`list_map`、`count_map`、`insert_map`、`fetch_db_item` 等），调用方直接使用 Model
- **`lib/download.php` 从 7 个函数精简为 3 个** — 删除 `add_download_task`、`fetch_related_users`，curl/文件逻辑保留
- **依赖链缩短**: `api → TaskModel` 替代 `api → lib/task.php → TaskModel`

### 兼容性

- **业务逻辑函数签名保持不变** — `uninstall_map($pdo, $id)`、`update_maps($pdo, $rows)` 等核心编排函数签名不变
- **API 响应格式不变** — JSON 结构与重构前完全一致
- **Daemon 长连接支持** — `TaskModel` 提供 `safe*` 方法（内部使用 `safe_execute()` 实现重连）
- **task_daemon.php 独立引导** — daemon 不走 bootstrap，独立 include 所需 Model 文件

### 附带修复

- `map_info.php` 管理员评论视图：SELECT 新增 `comments.id` 字段（此前缺失，导致 `$comment['id']` 未定义）
- `fetch_related_users()`: 修复了通过 `map_request_users.id` 查询 `user_id` 的 bug，改用 `MapRequestUserModel::getUserIdsBySteamId()` 一条 JOIN 查询替代 N+1 查询

## 加载机制

- **HTTP 请求**: `bootstrap.php`（nginx `auto_prepend_file` 注入）统一加载所有 Model
- **CLI daemon**: `task_daemon.php` 独立 include 所需 Model 文件
- **lib/debug.php**: 暂不迁移（仅限 CLI 调试用途）

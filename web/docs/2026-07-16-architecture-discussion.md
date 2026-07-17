# 新架构讨论

> 2026-07-16 | 基于审计结果和代码分析

## 背景

P0-P2 修复完成后，项目安全性和代码结构已有明显改善。接下来讨论架构层面是否需要更根本的调整。

当前特征：
- 18 个 PHP 文件，~2000 行有效逻辑
- 8 张 MySQL 表，多为单表 CRUD
- 原生 PHP，无框架，无 Composer
- 外部依赖：Steam API、腾讯 COS、腾讯 SES（均已自实现 HTTP 客户端）
- Docker Compose 部署，单人维护
- 游戏社区小站，非高并发场景

## 一、是否引入框架

### 方案对比

| | Laravel/Symfony | Slim/Flight 微框架 | 保持原生 + 局部结构化 |
|---|---|---|---|
| 新增依赖 | 100+ 包 | 5-8 包 | 0 |
| vendor 体积 | ~50MB | ~2MB | 0 |
| 代码改动量 | 全部重写 | 60% 重写 | 15-20% |
| 部署变更 | 新 Dockerfile | 新 Dockerfile | 无 |
| 学习成本 | 高 | 中 | 低 |
| ORM | ✅ Eloquent | ❌ 需额外加 | ❌ 手写 PDO（当前已够用） |
| 模板引擎 | Blade | 需额外选 | PHP 原生（当前已够用） |
| 适合当前规模 | ❌ 过度工程化 | ⚠️ 可选 | ✅ 最匹配 |

### 结论

**不引入任何框架。** 理由不是排斥框架，而是项目规模不匹配：

- ~2000 行业务逻辑不值得引入 100+ vendor 包的框架
- lib/ 结构已经合理——函数索引清晰、依赖关系明确
- 数据库操作是简单 CRUD，PDO prepared statement 已够用

真正痛点不在"缺少框架"，而在**页面文件混写**和**bootstrap 重复**。

## 二、路由：保持直接文件路径 vs 引入路由系统

### 当前模式

```
URL 路径             → 文件路径                → 执行
/billboard.php       → src/billboard.php       → bootstrap + SQL + HTML
/api/map_manage.php  → src/api/map_manage.php  → bootstrap + switch(action)
/api/check_email.php → src/api/check_email.php → bootstrap + JSON
```

nginx 直接转发给 PHP-FPM，没有中间路由层。

### 直接文件路径的优缺点

**优点：**

1. **零认知负担** — 文件路径即文档，`/billboard.php` 出 bug → 直接打开 `billboard.php`，没有任何中间层
2. **部署零配置** — 没有 rewrite 规则、路由缓存、路由表维护
3. **新增端点零摩擦** — 复制文件改几行即可上线，不需要改路由配置
4. **文件级别故障隔离** — 一个文件语法错误只影响该页面，不会导致全站 500
5. **匹配项目规模** — 18 个文件不值得引入路由抽象

**缺点：**

1. **Bootstrap include 重复** — 每个文件开头都有相同的 3-5 行 include
2. **中间件无法集中控制** — 想加一个全局中间件需要改 8 个文件
3. **URL 与文件结构绑定** — 无法使用 clean URL（如 `/api/maps`）
4. **`?action=` 分发是隐式路由** — `switch($action)` 藏在大文件里，不可见

### 关键判断：这些问题是否由"直接文件路径"造成？

| 问题 | 根因是直接文件路径吗？ | 解决方案 |
|------|----------------------|---------|
| include 重复 | **否** — 是缺少统一 bootstrap | 加 `bootstrap.php` + `auto_prepend_file` |
| 中间件分散 | **否** — 同样可用 `auto_prepend_file` 解决 | php.ini 一行配置 |
| 缺少 clean URL | **是** | 换路由方案 |
| `?action=` 隐式路由 | **是** | 要么接受，要么换路由 |
| 权限/CSRF 不一致 | **否** — 是代码质量问题 | 已在 P0-4, P2-15 修复 |

### 结论

**保持直接文件路径，不引入路由系统。** Clean URL 和 RESTful 风格对这个项目没有实际收益（无 SEO 需求、前端 JS URL 已固定、请求量小）。

当前 `?action=` 隐式路由的问题可以通过文档化解决（README 已列出所有 action）。

## 三、auto_prepend_file：无框架的统一入口

### 方案

利用 PHP-FPM 的 `auto_prepend_file` 指令，在每个请求前自动执行一个 bootstrap 文件，无需修改任何业务文件。

```ini
; php-fpm pool config
auto_prepend_file = /var/www/html/src/bootstrap.php
```

```php
// bootstrap.php — 每个请求自动执行
require_once __DIR__ . '/config.php';
require_once LIB_DIR . 'core.php';
require_once LIB_DIR . 'auth.php';

session_start();
csrf_token();  // 确保 CSRF token 存在
```

### 效果

业务文件从：

```php
<?php
include_once __DIR__ . '/../config.php';
include_once LIB_DIR . 'core.php';
include_once LIB_DIR . 'auth.php';
header('Content-Type: application/json');

// 认证
if (!check_login()) json_error('请先登录');
if (!check_admin()) json_error('权限不足');
if (!verify_csrf()) json_error('CSRF 验证失败');

// ... 业务逻辑
```

变为：

```php
<?php
// config/core/auth/session 已由 bootstrap 自动完成

header('Content-Type: application/json');

if (!check_admin()) json_error('权限不足');
if (!verify_csrf()) json_error('CSRF 验证失败');

// ... 业务逻辑
```

**不改文件路径结构，不改 URL，只消除 18 个文件中的重复 include。**

### 优点

- 零代码改动（除了加 bootstrap.php 和 php.ini 一行配置）
- 中间件可以集中在 bootstrap 中链式调用
- 仍然保留"文件路径即入口"的零认知负担
- 故障隔离不变（bootstrap 语法错误确实会影响全站，但 bootstrap 本身极简且不常改动）

### 风险

- `auto_prepend_file` 影响所有 PHP 请求，包括静态资源代理（需要确认 nginx 只把 `.php` 文件发给 PHP-FPM，当前配置已满足）
- bootstrap 有语法错误会导致全站 500（需在部署前严格检查）

## 四、页面文件混写问题

### 现状

`billboard.php`、`personal.php`、`map_info.php` 三个文件混合了：
- 数据库查询
- 业务逻辑
- HTML 渲染
- 内联 JavaScript
- 内联 CSS

### 改进方向

不引入模板引擎，只做文件拆分：

```
pages/
├── billboard.php       ← 数据查询 + 组装变量
├── personal.php        ← 同上
└── map_info.php        ← 同上

templates/
├── layout.php          ← printHeader / printNavbar / printFooter
├── map_grid.php        ← print_map_info() 的 HTML 部分
├── map_paginator.php   ← print_paginator() 的 HTML 部分
├── search_bar.php      ← print_search_bar() 的 HTML 部分
├── profile_tab.php     ← printProfile() 的 HTML 部分
├── inbox_tab.php       ← printInbox() 的 HTML 部分
└── ...
```

拆分原则：
- `pages/` 文件只负责：鉴权 → 调 lib 取数据 → 传变量给模板
- `templates/` 文件只负责：接收变量 → 输出 HTML（纯展示逻辑）
- SQL 全部在 `lib/` 中，不在 page 中出现

## 五、推荐实施顺序

| 步骤 | 内容 | 改动量 |
|------|------|--------|
| 1 | 创建 `bootstrap.php` + 配置 `auto_prepend_file` | 1 个新文件 + 1 行配置 |
| 2 | 清理各文件的重复 include | 批量删除 18 个文件中的重复行 |
| 3 | 拆分 `billboard.php`（混写最严重的文件） | ~150 行 HTML 移入 templates |
| 4 | 拆分 `personal.php` | 4 个 tab 各拆一个模板 |
| 5 | 拆分 `map_info.php` | CarouselGenerator 类独立 + 评论模板 |

步骤 1-2 可以立即执行（风险低，收益立竿见影）。
步骤 3-5 按需推进（不影响功能，纯改善可读性）。

# sidecar — 容器管理 API

基于 PHP 内置服务器的轻量容器管理接口，挂载 `docker.sock` 与宿主机 Docker 通信。

## API 端点

sidecar 不直接暴露公网，前端通过 php 的 `/api/containers.php` 代理访问（登录 + admin，写操作另需 CSRF），由 php 服务端转发调用。

**对外端点**（`/api/containers.php`）：

| 端点 | 方法 | 说明 |
|------|------|------|
| `?action=list` | GET | 列出容器 |
| `?action=logs&name=&tail=` | GET | 查看容器日志（最多 200 行） |
| `?action=restart&name=` | POST | 重启容器（需在 `RESTARTABLE_CONTAINERS` 内） |

**内部端点**（sidecar 自身，`X-Auth-Token` 头）：

| 端点 | 方法 | 认证 | 说明 |
|------|------|------|------|
| `/health` | GET | — | 健康检查 |
| `/containers` | GET | Token | 列出运行中的容器（`ALLOWED_CONTAINERS` 白名单过滤） |
| `/containers/{name}/logs?tail=50` | GET | Token | 查看容器日志（最多 200 行） |
| `/containers/{name}/restart` | POST | Token | 重启容器（需在 `RESTARTABLE_CONTAINERS` 内） |

## 认证

除 `/health` 外，所有请求需要 `X-Auth-Token` 头匹配 `SIDECAR_TOKEN` 环境变量。Token 为空则拒绝所有请求（返回 503）。

> sidecar 不直接暴露公网，由 php 容器 `/api/containers.php` 代理访问（需登录 + admin + CSRF），`SIDECAR_TOKEN` 仅在服务端传递。

## 容器白名单

由两个环境变量控制（逗号分隔）：

| 变量 | 默认值 | 作用 |
|------|--------|------|
| `ALLOWED_CONTAINERS` | `l4d2-task-daemon,l4d2-coop,l4d2-versus,l4d2-php,l4d2-mysql,l4d2-glances,l4d2-nginx` | 可查看/日志的容器 |
| `RESTARTABLE_CONTAINERS` | `l4d2-task-daemon,l4d2-coop,l4d2-versus` | 可重启的容器 |

## 运行时

- `server.php` 通过 volume mount 注入容器（`./sidecar/server.php:/server.php:ro`）
- 修改后 `docker compose restart sidecar` 即生效，无需重新构建
- 镜像额外安装 `docker-cli`（~50MB），基础层复用 `base-php-cli`

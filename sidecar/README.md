# sidecar — 容器管理 API

基于 PHP 内置服务器的轻量容器管理接口，挂载 `docker.sock` 与宿主机 Docker 通信。

## API 端点

| 端点 | 方法 | 认证 | 说明 |
|------|------|------|------|
| `/health` | GET | — | 健康检查 |
| `/containers` | GET | Token | 列出运行中的容器（`ALLOWED_CONTAINERS` 白名单过滤） |
| `/containers/{name}/logs?tail=50` | GET | Token | 查看容器日志（最多 200 行） |
| `/containers/{name}/restart` | POST | Token | 重启容器（需在 `RESTARTABLE_CONTAINERS` 内） |

## 认证

除 `/health` 外，所有请求需要 `X-Auth-Token` 头匹配 `SIDECAR_TOKEN` 环境变量。Token 为空则跳过认证。

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

# nginx — 反向代理

## 路由分发

```
请求进入 :80/:443
  ├── /api/*              → fastcgi_pass php:9000（容器管理 /api/containers.php、监控 /api/monitor.php 均由 php 服务端转发）
  ├── *.php               → fastcgi_pass php:9000
  └── *.css/.js/.png/...  → 直接返回静态文件
```

## 静态资源缓存

| 路径 | 缓存时间 |
|------|----------|
| `*.jpg/png/svg/woff2/mp3/ico` | 30 天 |
| `static/js/jquery.*.js` | 30 天 |
| `static/css/bootstrap.*.css` | 30 天 |
| `static/js/custom/*.js` | 5 分钟 |
| `*.css` / `*.js`（其他） | 5 分钟 |

## SSL 证书

证书文件放在 `nginx/data/certs/`：

| 文件 | 说明 |
|------|------|
| `fullchain.pem` | 完整证书链 |
| `privkey.pem` | 私钥 |

支持 HTTP（80）和 HTTPS（443）。HTTP 允许浏览公开内容，登录/注册跳转到 HTTPS。

## 关键文件

| 文件 | 说明 |
|------|------|
| `nginx/data/conf.d/l4d2.conf` | server block + upstream 定义 |
| `nginx/data/conf.d/common.inc` | 共用路由规则（被 HTTP/HTTPS server include） |
| `nginx/data/certs/` | SSL 证书 |
| `nginx/Dockerfile` | `nginx:alpine` 基础镜像 |

## 特殊配置

- `client_max_body_size 8m` — 与 PHP `post_max_size`(8M) 对齐；web 无大文件上传（地图 .vpk 由 task-daemon 下载，不走 nginx）

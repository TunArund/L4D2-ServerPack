# nginx — 反向代理

## 路由分发

```
请求进入 :80/:443
  ├── /api/*              → fastcgi_pass php:9000
  ├── *.php               → fastcgi_pass php:9000
  ├── /manage/*           → proxy_pass sidecar:8080
  ├── /monitor-api/*      → proxy_pass host.docker.internal:61208
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

支持 HTTP（80）和 HTTPS（443），HTTP 不强制跳转。

## 关键文件

| 文件 | 说明 |
|------|------|
| `nginx/data/conf.d/l4d2.conf` | server block + upstream 定义 |
| `nginx/data/conf.d/common.inc` | 共用路由规则（被 HTTP/HTTPS server include） |
| `nginx/data/certs/` | SSL 证书 |
| `nginx/Dockerfile` | `nginx:alpine` 基础镜像 |

## 特殊配置

- `client_max_body_size 2048m` — 允许上传大 vpk 文件

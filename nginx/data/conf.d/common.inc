# ============================================================
# l4d2.tunarund.top — 共用路由规则
# 由 l4d2.conf 的 HTTP / HTTPS server block 各自 include
# ============================================================

root /var/www/html;
index index.php;
client_max_body_size 2048m;

# ---- 第三方静态资源：长缓存 ----
location ~* \.(jpg|jpeg|png|gif|webp|svg|woff|woff2|mp3|ico)$ {
    expires 30d;
    add_header Cache-Control "public, max-age=2592000";
}
location ~* /static/(css|js|font)/bootstrap.*\.(css|js|map)$ {
    expires 30d;
    add_header Cache-Control "public, max-age=2592000";
}
location ~* /static/js/jquery.*\.js$ {
    expires 30d;
    add_header Cache-Control "public, max-age=2592000";
}

# ---- 自定义 JS/CSS：短缓存 ----
location ~* /static/js/custom/.*\.js$ {
    expires 5m;
    add_header Cache-Control "public, max-age=300";
}
location ~* \.css$ {
    expires 5m;
    add_header Cache-Control "public, max-age=300";
}
location ~* \.js$ {
    expires 5m;
    add_header Cache-Control "public, max-age=300";
}

# ---- PHP → FastCGI ----
location ~ \.php$ {
    fastcgi_pass php_backend;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param PHP_VALUE "auto_prepend_file=/var/www/html/etc/bootstrap.php";
    include fastcgi_params;
}

# ---- /manage → sidecar ----
location /manage/ {
    proxy_pass http://sidecar_backend/;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# ---- /monitor-api → Glances REST API ----
location /monitor-api/ {
    proxy_pass http://glances_backend/;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}

# ---- / → 前端路由 fallback ----
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

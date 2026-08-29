# ============================================================
# l4d2.tunarund.top — 共用路由规则
# 由 l4d2.conf 的 HTTP / HTTPS server block 各自 include
# ============================================================

root /var/www/html;
index index.php;
# 无大文件上传（地图 .vpk 由 task-daemon 下载，不走 nginx），上限与 PHP post_max_size(8M) 对齐
client_max_body_size 8m;

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
    limit_req zone=auth burst=5 nodelay;
    limit_req zone=email burst=3 nodelay;
    limit_req_status 429;

    fastcgi_pass php_backend;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param PHP_VALUE "auto_prepend_file=/var/www/html/etc/bootstrap.php";
    fastcgi_param HTTPS $https;
    fastcgi_param HTTP_X_AUTH_TOKEN $http_x_auth_token;
    include fastcgi_params;
}

# ---- / → 前端路由 fallback ----
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

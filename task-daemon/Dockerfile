ARG REGISTRY
FROM ${REGISTRY}l4d2-base-php-cli:latest

ARG APK_MIRROR="mirrors.aliyun.com"
RUN if [ -n "$APK_MIRROR" ]; then \
        sed -i "s|dl-cdn.alpinelinux.org|${APK_MIRROR}|g" /etc/apk/repositories; \
    fi

# 运行时依赖
RUN apk add --no-cache shadow util-linux

ARG APP_UID=1000
ARG APP_GID=1000
RUN if ! grep -q "^.*:.*:${APP_GID}:" /etc/group 2>/dev/null; then \
        addgroup -g "$APP_GID" taskdaemon 2>/dev/null || true; \
    fi && \
    if ! id -u "$APP_UID" >/dev/null 2>&1; then \
        adduser -u "$APP_UID" -G "$(getent group "$APP_GID" | cut -d: -f1)" -D -s /bin/sh taskdaemon 2>/dev/null || true; \
    fi

USER taskdaemon
WORKDIR /var/www/html
CMD ["php", "/var/www/html/bin/task_daemon.php"]

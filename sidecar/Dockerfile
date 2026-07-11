ARG REGISTRY
FROM ${REGISTRY}l4d2-base-php-cli:latest

ARG APK_MIRROR="mirrors.aliyun.com"
RUN if [ -n "$APK_MIRROR" ]; then \
        sed -i "s|dl-cdn.alpinelinux.org|${APK_MIRROR}|g" /etc/apk/repositories; \
    fi

# docker-cli — 与宿主机 Docker socket 通信
RUN apk add --no-cache docker-cli

EXPOSE 8080
ENV ALLOWED_CONTAINERS="l4d2-task-daemon,l4d2-coop,l4d2-versus,l4d2-php,l4d2-mysql,l4d2-glances,l4d2-nginx"
CMD ["php", "-S", "0.0.0.0:8080", "/server.php"]

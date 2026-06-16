ARG REGISTRY
FROM ${REGISTRY}l4d2-base-php-cli:latest

ARG APK_MIRROR="mirrors.aliyun.com"
RUN if [ -n "$APK_MIRROR" ]; then \
        sed -i "s|dl-cdn.alpinelinux.org|${APK_MIRROR}|g" /etc/apk/repositories; \
    fi

# 运行时依赖
RUN apk add --no-cache shadow util-linux

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /var/www/html
ENTRYPOINT ["/entrypoint.sh"]

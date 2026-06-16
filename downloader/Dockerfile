ARG REGISTRY
FROM ${REGISTRY}l4d2-base-php-cli:latest

# 运行时依赖
RUN apk add --no-cache shadow util-linux

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /var/www/html
ENTRYPOINT ["/entrypoint.sh"]

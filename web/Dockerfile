ARG REGISTRY
FROM ${REGISTRY}l4d2-base-php-fpm:latest

# PHP-FPM worker 用户（通过 build arg 传入，默认 1000）
ARG APP_UID=1000
ARG APP_GID=1000
RUN sed -i "s/^user = www-data$/user = ${APP_UID}/" /usr/local/etc/php-fpm.d/www.conf && \
    sed -i "s/^group = www-data$/group = ${APP_GID}/" /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html
EXPOSE 9000

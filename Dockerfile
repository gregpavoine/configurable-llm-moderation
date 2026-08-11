# syntax=docker/dockerfile:1.7

FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
COPY src ./src
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --classmap-authoritative

FROM vendor AS vendor-dev

RUN composer install \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --classmap-authoritative

FROM php:8.5-fpm-bookworm AS app

ENV APP_ENV=prod \
    APP_DEBUG=0

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
    && docker-php-ext-install -j"$(nproc)" intl pcntl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --chown=www-data:www-data . ./
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --chown=root:root docker/app/init.sh /usr/local/bin/app-init

RUN chmod 0755 /usr/local/bin/app-init \
    && mkdir -p var config/jwt \
    && chown -R www-data:www-data var config/jwt

USER www-data

FROM app AS app-dev

COPY --from=vendor-dev --chown=www-data:www-data /app/vendor ./vendor

FROM nginxinc/nginx-unprivileged:1.29-alpine AS web

COPY --chown=nginx:nginx public /app/public
COPY --chown=nginx:nginx docker/nginx/default.conf /etc/nginx/conf.d/default.conf

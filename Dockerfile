# SPDX-FileCopyrightText: 2026 LibreSign contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
FROM composer:2.11.0@sha256:278dfc7bd35169965d03f87194816f43bf4e6b6ea61707c2114e52ad12fe24ce AS composer-bin

FROM php:8.5.12-fpm-alpine@sha256:4c2818074ec49f6abe46620f3965e0cb75bc3a5f2b417edd1aacaba6f091c40e AS base
# The pinned PHP 8.5 image already ships mbstring and OPcache.
RUN php -r 'exit(extension_loaded("mbstring") && extension_loaded("Zend OPcache") ? 0 : 1);'
COPY docker/php/php.ini /usr/local/etc/php/conf.d/pricing.ini
RUN printf '[www]\nclear_env = no\npm.max_children = 4\npm.start_servers = 2\npm.min_spare_servers = 1\npm.max_spare_servers = 2\npm.max_requests = 250\n' \
    > /usr/local/etc/php-fpm.d/zz-pricing.conf

FROM base AS dependencies
WORKDIR /app
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
COPY src/ src/
COPY config/ config/
COPY public/ public/
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --no-dev --no-plugins --no-scripts --prefer-dist \
        --no-interaction --no-progress --classmap-authoritative \
    && php -v \
    && php -r 'exit(PHP_VERSION_ID >= 80512 ? 0 : 1);'

FROM base AS runtime
WORKDIR /app
COPY --from=dependencies --chown=10001:10001 /app/ /app/
COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/pricing-entrypoint
RUN mkdir -p /app/var \
    && chown -R 10001:10001 /app \
    && chmod 0700 /app/var
ENV APP_ENV=prod APP_DEBUG=0
USER 10001:10001
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/pricing-entrypoint"]
CMD ["php-fpm", "-F"]

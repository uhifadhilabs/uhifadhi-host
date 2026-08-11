# syntax=docker/dockerfile:1
# uhifadhi production image — FrankenPHP (Caddy) + PHP 8.4 with the PostgreSQL/PostGIS
# client extension, built for Kamal. Single artifact: PHP app + built Tailwind/AssetMapper.
FROM dunglas/frankenphp:1-php8.4 AS base

WORKDIR /app

# System libs + PHP extensions (pdo_pgsql for Postgres/PostGIS, intl/zip/bcmath, opcache).
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libicu-dev libzip-dev \
    && install-php-extensions pdo_pgsql intl zip opcache bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Production php.ini + opcache tuning.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY .docker/opcache.ini $PHP_INI_DIR/conf.d/zz-opcache.ini

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:80 \
    COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 1) Dependency layer — cached until composer.{json,lock} change.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --no-autoloader

# 2) Application source.
COPY . .

# 3) Optimise autoloader + build assets (no secrets/DB needed at build time).
#    importmap:install fetches vendor JS (stimulus/turbo/leaflet…) since assets/vendor is gitignored.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php bin/console importmap:install \
    && php bin/console tailwind:build --minify \
    && php bin/console asset-map:compile \
    && mkdir -p var && chown -R www-data:www-data var \
    && chmod +x .docker/docker-entrypoint.sh

# Cache is warmed at container start (runtime env available). See entrypoint.
ENTRYPOINT ["/app/.docker/docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

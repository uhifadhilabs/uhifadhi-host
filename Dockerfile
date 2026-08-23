# syntax=docker/dockerfile:1
# uhifadhi production image — FrankenPHP (Caddy) + PHP 8.4 with the PostgreSQL/PostGIS
# client extension, built for Kamal. Single artifact: PHP app + built Tailwind/AssetMapper.
FROM dunglas/frankenphp:1-php8.4 AS base

WORKDIR /app

# System libs + PHP extensions (pdo_pgsql for Postgres/PostGIS, intl/zip/bcmath, opcache).
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libicu-dev libzip-dev curl bzip2 ca-certificates \
    && install-php-extensions pdo_pgsql intl zip opcache bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# GDAL for the Hansen ingestion — the handler uses the unified `gdal raster polygonize`
# CLI, which is evolving FAST across releases (e.g. 3.11.5 rejects `--quiet` on that
# subcommand, 3.13 accepts it). So pin prod to the SAME major.minor as dev (3.13) to
# avoid flag skew. Debian ships only 3.6, so install via conda-forge into /opt/gdal —
# self-contained, version-pinned, reads granules over /vsicurl.
# TODO (tech debt): drop conda-forge for `apt-get install gdal-bin` once a stable
# Debian/Ubuntu release ships this GDAL. Keep the pin in lockstep with the dev GDAL
# version (which the ingestion handler's CLI flags target) — a mismatch breaks ingestion.
RUN curl -Ls https://micro.mamba.pm/api/micromamba/linux-64/latest | tar -xj -C /usr/local bin/micromamba \
    && /usr/local/bin/micromamba create -y -p /opt/gdal -c conda-forge "gdal=3.13" \
    && /usr/local/bin/micromamba clean -a -y \
    && rm -f /usr/local/bin/micromamba
ENV PATH="/opt/gdal/bin:${PATH}" \
    GDAL_DATA="/opt/gdal/share/gdal" \
    PROJ_DATA="/opt/gdal/share/proj" \
    LD_LIBRARY_PATH="/opt/gdal/lib"

# Production php.ini + opcache tuning.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY .docker/opcache.ini $PHP_INI_DIR/conf.d/zz-opcache.ini

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:80 \
    COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 1) Dependency layer — cached until composer.{json,lock} change. The private
#    uhifadhilabs packages install from GitHub vcs repos, so composer needs a
#    token: COMPOSER_AUTH (auth.json JSON, read natively by composer) comes in
#    as a BuildKit secret (kamal builder.secrets) — never baked into a layer.
COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=secret,id=COMPOSER_AUTH \
    COMPOSER_AUTH="$(cat /run/secrets/COMPOSER_AUTH 2>/dev/null || true)" \
    composer install --no-dev --no-scripts --no-progress --prefer-dist --no-autoloader

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

#!/bin/sh
set -e

# Warm the Symfony cache at container start — this is where runtime env vars and
# the DATABASE_URL are available. Non-fatal so a cold DB never blocks boot.
if [ "$APP_ENV" = "prod" ]; then
    php bin/console cache:clear --no-interaction || true
    php bin/console cache:warmup --no-interaction || true
fi

# Hand off to the PHP base image entrypoint, which execs the CMD (frankenphp run …).
exec docker-php-entrypoint "$@"

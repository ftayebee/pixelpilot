#!/bin/sh
# Builds Laravel's caches from the runtime environment, then runs the
# container's command (php-fpm, queue:work or schedule:work).
set -e

cd /var/www/html

if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan config:cache >/dev/null
    php artisan route:cache >/dev/null
    php artisan view:cache >/dev/null
    php artisan event:cache >/dev/null
fi

exec "$@"

#!/bin/sh -eu

# Browser used by snappdf for PDF generation
if [ "$(dpkg --print-architecture)" = "amd64" ]; then
    export SNAPPDF_CHROMIUM_PATH=/usr/bin/google-chrome-stable
else
    export SNAPPDF_CHROMIUM_PATH=/usr/bin/chromium
fi

if [ "$*" = 'supervisord -c /etc/supervisor/supervisord.conf' ]; then

    # Required folders (created inside the mounted volumes on first run)
    [ -d /var/www/html/public ] || mkdir -p /var/www/html/public
    [ -d /var/www/html/storage/app/public ] || mkdir -p /var/www/html/storage/app/public
    [ -d /var/www/html/storage/framework/sessions ] || mkdir -p /var/www/html/storage/framework/sessions
    [ -d /var/www/html/storage/framework/views ] || mkdir -p /var/www/html/storage/framework/views
    [ -d /var/www/html/storage/framework/cache ] || mkdir -p /var/www/html/storage/framework/cache

    # Sync the image's public files into the public volume when a new container
    # starts, then clear the source so a restart does not overwrite files
    # created at runtime (e.g. .well-known).
    if [ "$(ls -A /tmp/public 2>/dev/null)" ]; then
        echo "Updating public folder..."
        rm -rf /var/www/html/public/.htaccess \
            /var/www/html/public/.well-known \
            /var/www/html/public/*
        cp -r /tmp/public/* /tmp/public/.htaccess /tmp/public/.well-known /var/www/html/public/
        rm -rf /tmp/public/.htaccess /tmp/public/.well-known /tmp/public/*
    fi
    echo "Public folder is up to date"

    # Permissions for the volume mounted folders
    chown -R www-data:www-data /var/www/html/public /var/www/html/storage
    find /var/www/html/public /var/www/html/storage -type f -exec chmod 644 {} \;
    find /var/www/html/public /var/www/html/storage -type d -exec chmod 755 {} \;

    if [ "${APP_ENV:-production}" = "production" ]; then
        # First boot race: the database may still be starting even though it
        # already answered its healthcheck, so wait for TCP connectivity.
        waits=0
        until MYSQL_PWD="${DB_PASSWORD:-ninja}" mysqladmin ping \
            --host="${DB_HOST:-db}" --port="${DB_PORT:-3306}" \
            --user="${DB_USERNAME:-ninja}" --silent >/dev/null 2>&1; do
            waits=$((waits + 1))
            if [ "$waits" -ge 60 ]; then
                echo "Database ${DB_HOST:-db}:${DB_PORT:-3306} is not reachable" >&2
                exit 1
            fi
            echo "Waiting for database..."
            sleep 2
        done

        runuser -u www-data -- php artisan migrate --force
        runuser -u www-data -- php artisan cache:clear
        runuser -u www-data -- php artisan ninja:design-update
        runuser -u www-data -- php artisan optimize
        runuser -u www-data -- php artisan db:seed --force

        accounts=$(runuser -u www-data -- php artisan tinker --execute='echo App\Models\Account::count();')
        if [ "$accounts" = "0" ]; then
            if [ -n "${IN_USER_EMAIL:-}" ] && [ -n "${IN_PASSWORD:-}" ]; then
                runuser -u www-data -- php artisan ninja:create-account \
                    --email "${IN_USER_EMAIL}" --password "${IN_PASSWORD}"
            else
                echo "No account found - open ${APP_URL:-http://localhost}/setup to create the first account"
            fi
        fi
        echo "Production setup completed"
    fi

    echo "Starting supervisord..."
fi

exec "$@"

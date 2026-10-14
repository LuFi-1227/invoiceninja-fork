#!/bin/sh -eu

# Set PDF generation browser path based on architecture
if [ "$(dpkg --print-architecture)" = "amd64" ]; then
    export SNAPPDF_CHROMIUM_PATH=/usr/bin/google-chrome-stable
elif [ "$(dpkg --print-architecture)" = "arm64" ]; then
    export SNAPPDF_CHROMIUM_PATH=/usr/bin/chromium
fi

if [ "$*" = 'supervisord -c /etc/supervisor/supervisord.conf' ]; then

    # Check for required folders and create if needed
    mkdir -p /var/www/html/bootstrap/cache
    chown -R www-data:www-data /var/www/html/bootstrap/cache
    chmod -R 775 /var/www/html/bootstrap/cache

    [ -d /var/www/html/public ] || mkdir -p /var/www/html/public
    [ -d /var/www/html/storage/app/public ] || mkdir -p /var/www/html/storage/app/public
    [ -d /var/www/html/storage/framework/sessions ] || mkdir -p /var/www/html/storage/framework/sessions
    [ -d /var/www/html/storage/framework/views ] || mkdir -p /var/www/html/storage/framework/views
    [ -d /var/www/html/storage/framework/cache ] || mkdir -p /var/www/html/storage/framework/cache

    # Ensure storage permissions
    chown -R www-data:www-data /var/www/html/storage
    chmod -R 775 /var/www/html/storage

    # Ensure public index.html symlink for React UI
    if [ ! -L /var/www/html/public/index.html ] && [ ! -f /var/www/html/public/index.html ]; then
        ln -sf /var/www/html/resources/views/react/index.blade.php /var/www/html/public/index.html
    fi

    if [ "${APP_ENV:-production}" = "production" ]; then
        waits=0
        until MYSQL_PWD="${DB_PASSWORD:-ninja}" mysqladmin ping \
            --host="${DB_HOST:-mysql}" --port="${DB_PORT:-3306}" \
            --user="${DB_USERNAME:-ninja}" --silent >/dev/null 2>&1; do
            waits=$((waits + 1))
            if [ "$waits" -ge 60 ]; then
                echo "Database ${DB_HOST:-mysql}:${DB_PORT:-3306} is not reachable" >&2
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

        # Ensure the client CPF/CNPJ custom field label exists (idempotent).
        # The backfill migration can run before the first company is created,
        # so re-apply it here after the account/company exists.
        runuser -u www-data -- php artisan tinker --execute='
            App\Models\Company::query()->each(function ($c) {
                $cf = (object) ($c->custom_fields ?? []);
                if (empty($cf->client1)) {
                    $cf->client1 = "CPF / CNPJ|single_line_text";
                } elseif (! str_contains($cf->client1, "|")) {
                    $cf->client1 = $cf->client1 . "|single_line_text";
                } else {
                    return;
                }
                $c->custom_fields = $cf;
                $c->saveQuietly();
            });
        '

        echo "Production setup completed"
    fi

    echo "Starting supervisord..."
fi

exec "$@"

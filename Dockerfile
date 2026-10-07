# syntax=docker/dockerfile:1

ARG PHP=8.3

# =========================
# Stage 1 - PHP dependencies
# =========================
FROM php:${PHP}-fpm AS composer

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions bcmath gd zip \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/tmp/composer-cache \
    --mount=type=secret,id=composer_auth \
    if [ -f /run/secrets/composer_auth ]; then export COMPOSER_AUTH="$(cat /run/secrets/composer_auth)"; fi; \
    composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist

COPY . .

RUN --mount=type=cache,target=/tmp/composer-cache \
    --mount=type=secret,id=composer_auth \
    if [ -f /run/secrets/composer_auth ]; then export COMPOSER_AUTH="$(cat /run/secrets/composer_auth)"; fi; \
    composer install --no-dev --no-interaction --no-progress --prefer-dist

# =========================
# Stage 2 - Frontend assets
# =========================
FROM node:20 AS assets

ENV CYPRESS_INSTALL_BINARY=0 \
    PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
    PUPPETEER_SKIP_DOWNLOAD=1

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY --from=composer /var/www/html/vendor ./vendor
COPY . .
RUN npm run build

# =========================
# Stage 3 - Runtime image
# =========================
FROM php:${PHP}-fpm

ARG php_require="bcmath gd mbstring pdo_mysql zip"
ARG php_suggest="exif imagick intl pcntl soap"
ARG php_extra="opcache"
ARG php_amd64="saxon"

RUN apt-get update && apt-get install -y --no-install-recommends \
    libfcgi-bin \
    mariadb-client \
    gpg \
    rsync \
    supervisor \
    curl \
    ca-certificates \
    fonts-noto-cjk-extra \
    fonts-wqy-microhei \
    fonts-wqy-zenhei \
    xfonts-wqy \
    && if [ "$(dpkg --print-architecture)" = "amd64" ]; then \
        mkdir -p /etc/apt/keyrings \
        && curl -fsSL https://dl.google.com/linux/linux_signing_key.pub \
            | gpg --dearmor -o /etc/apt/keyrings/google.gpg \
        && echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/google.gpg] https://dl.google.com/linux/chrome/deb/ stable main" \
            > /etc/apt/sources.list.d/google-chrome.list \
        && apt-get update \
        && apt-get install -y --no-install-recommends google-chrome-stable; \
       else \
        apt-get install -y --no-install-recommends chromium; \
       fi \
    && mkdir -p /var/www/.config \
    && chown www-data:www-data /var/www/.config \
    && apt-get purge -y gpg \
    && apt-get autoremove -y \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN if [ "$(dpkg --print-architecture)" = "amd64" ]; then \
        IPE_SAXON_EDITION=HE install-php-extensions ${php_require} ${php_suggest} ${php_amd64} ${php_extra}; \
    else \
        install-php-extensions ${php_require} ${php_suggest} ${php_extra}; \
    fi

RUN ln -s "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini /usr/local/etc/php/conf.d/invoiceninja.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/invoiceninja.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/programs.conf /etc/supervisor/conf.d/programs.conf

WORKDIR /var/www/html

COPY --from=composer --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

RUN runuser -u www-data -- php artisan storage:link \
    && mv /var/www/html/public /tmp/public \
    && chown -R www-data:www-data /tmp/public

# mariadb-client must not use TLS against MySQL 8 (self-signed certificate)
RUN echo "skip-ssl = true" >> /etc/mysql/mariadb.conf.d/50-client.cnf

COPY --chmod=0755 docker/init.sh /usr/local/bin/init.sh

# start-period must cover the first boot (migrations + seeding can take minutes)
HEALTHCHECK --start-period=480s \
    CMD REMOTE_ADDR=127.0.0.1 REQUEST_URI=/health REQUEST_METHOD=GET \
        SCRIPT_FILENAME=/var/www/html/public/index.php \
        cgi-fcgi -bind -connect 127.0.0.1:9000 \
        | grep '{"status":"ok","message":"API is healthy"}'

ENTRYPOINT ["/usr/local/bin/init.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]

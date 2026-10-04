# syntax=docker/dockerfile:1

# memry Community image: Laravel served by FrankenPHP.
# The lockfile requires PHP 8.4, so the runtime pins the php8.4 FrankenPHP variant.

ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.4-bookworm

# ---------------------------------------------------------------------------
# PHP base: FrankenPHP + the extensions the app and its dependencies need.
# ---------------------------------------------------------------------------
FROM ${FRANKENPHP_IMAGE} AS php-base

RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        intl \
        opcache \
        zip \
        bcmath \
        pcntl

# ---------------------------------------------------------------------------
# Frontend assets (Vite). The built JS and CSS are the same on every
# architecture, so this stage runs natively on the build machine instead of
# under QEMU emulation in multi-arch builds.
# ---------------------------------------------------------------------------
FROM --platform=$BUILDPLATFORM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---------------------------------------------------------------------------
# Composer dependencies (production only).
# ---------------------------------------------------------------------------
FROM php-base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && rm -f bootstrap/cache/*.php \
    && php artisan package:discover --ansi

# ---------------------------------------------------------------------------
# Runtime.
# ---------------------------------------------------------------------------
FROM php-base AS runtime

ARG APP_UID=1000
ARG APP_GID=1000

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=pgsql \
    SERVER_PORT=8000 \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'expose_php=Off'; \
    } > "$PHP_INI_DIR/conf.d/zz-memry.ini"

WORKDIR /app

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/entrypoint.sh /usr/local/bin/memry

# FrankenPHP ships with cap_net_bind_service; it is not needed on port 8000.
RUN setcap -r /usr/local/bin/frankenphp || true

RUN groupadd --gid "${APP_GID}" memry \
    && useradd --uid "${APP_UID}" --gid memry --no-create-home --shell /usr/sbin/nologin memry \
    && chmod 0755 /usr/local/bin/memry \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /config/caddy /data/caddy \
    && chown -R memry:memry storage bootstrap/cache /config /data

USER memry

EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=5 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:" . (getenv("SERVER_PORT") ?: "8000") . "/up") === false ? 1 : 0);'

ENTRYPOINT ["memry"]
CMD ["serve"]

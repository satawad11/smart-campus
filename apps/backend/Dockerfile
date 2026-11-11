# Backend (Laravel) — development image (FND-003)
#
# Provides PHP-FPM with the extensions the locked stack needs (PostgreSQL via
# pdo_pgsql, Redis via the phpredis extension). Application-specific runtime
# wiring (migrations, Reverb, etc.) is handled by later tasks/modules.
FROM php:8.2-fpm-alpine

# Build/runtime system dependencies. postgresql16-client provides pg_dump/psql
# matching the PostgreSQL 16 server, required by backup:run (PILOT-003).
# ffmpeg grabs one camera frame for snapshot and the add-camera preview.
RUN apk add --no-cache \
        git curl libpq-dev oniguruma-dev libzip-dev postgresql16-client ffmpeg \
    && docker-php-ext-install pdo pdo_pgsql mbstring bcmath opcache pcntl zip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Composer (from the official image).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP dependencies first for better layer caching.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader

# Copy the rest of the application and finish autoload/scripts.
COPY . .
RUN composer dump-autoload --optimize

# php-fpm's worker processes run as www-data (see the pool config baked into
# this image), but everything above was just COPY'd in as root — without
# this, www-data can write to none of storage/, bootstrap/cache/, or
# database/ (the SQLite dev DB's directory: SQLite needs to write a
# rollback-journal file *alongside* database.sqlite on every write, so the
# directory itself must be writable, not just the .sqlite file). Left
# unfixed, every session write, log write, view/cache compile, and SQLite
# write from a real request silently fails ("attempt to write a readonly
# database"), while CLI commands (which typically run as root) keep working
# — masking the bug until it's hit through a real HTTP request.
RUN mkdir -p database \
    && chown -R www-data:www-data storage bootstrap/cache database

EXPOSE 9000
CMD ["php-fpm"]

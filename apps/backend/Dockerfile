# Backend (Laravel) — development image (FND-003)
#
# Provides PHP-FPM with the extensions the locked stack needs (PostgreSQL via
# pdo_pgsql, Redis via the phpredis extension). Application-specific runtime
# wiring (migrations, Reverb, etc.) is handled by later tasks/modules.
FROM php:8.2-fpm-alpine

# Build/runtime system dependencies.
RUN apk add --no-cache \
        git curl libpq-dev oniguruma-dev libzip-dev \
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

EXPOSE 9000
CMD ["php-fpm"]

FROM php:8.4-cli-alpine

# Install build dependencies and PHP extensions
RUN apk add --no-cache \
        $PHPIZE_DEPS \
        linux-headers \
    && docker-php-ext-install pdo_mysql pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS linux-headers

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html/backend

# Copy backend source
COPY backend/ .

# Install production dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

CMD ["php", "game_worker.php"]

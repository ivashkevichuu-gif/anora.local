FROM php:8.4-fpm-alpine

# Install build dependencies and PHP extensions
RUN apk add --no-cache \
        $PHPIZE_DEPS \
        linux-headers \
        msmtp \
    && docker-php-ext-install pdo_mysql pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS linux-headers \
    && echo 'sendmail_path = "/usr/bin/msmtp -t"' > /usr/local/etc/php/conf.d/mail.ini

# Copy msmtp config (SMTP relay for mail())
COPY docker/php-fpm/msmtprc /etc/msmtprc
RUN chmod 600 /etc/msmtprc

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html/backend

# Copy backend source
COPY backend/ .

# Install production dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

EXPOSE 9000

CMD ["php-fpm"]

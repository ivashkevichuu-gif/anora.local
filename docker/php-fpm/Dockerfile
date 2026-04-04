FROM php:8.4-fpm-alpine

# Install build dependencies, PHP extensions, and Postfix for mail()
RUN apk add --no-cache \
        $PHPIZE_DEPS \
        linux-headers \
        postfix \
    && docker-php-ext-install pdo_mysql pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS linux-headers

# Configure Postfix for direct sending from noreply@anora.bet
RUN postconf -e "myhostname = anora.bet" \
    && postconf -e "mydomain = anora.bet" \
    && postconf -e "myorigin = anora.bet" \
    && postconf -e "inet_interfaces = loopback-only" \
    && postconf -e "mydestination = " \
    && postconf -e "relayhost = " \
    && postconf -e "smtp_tls_security_level = may" \
    && postconf -e "smtp_tls_CAfile = /etc/ssl/certs/ca-certificates.crt"

# Set PHP to use sendmail (Postfix)
RUN echo 'sendmail_path = "/usr/sbin/sendmail -t -i"' > /usr/local/etc/php/conf.d/mail.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html/backend

# Copy backend source
COPY backend/ .

# Install production dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Start script: launch Postfix then PHP-FPM
COPY docker/php-fpm/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 9000

CMD ["/start.sh"]

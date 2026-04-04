#!/bin/sh
# Start Postfix in background, then PHP-FPM in foreground
postfix start
exec php-fpm

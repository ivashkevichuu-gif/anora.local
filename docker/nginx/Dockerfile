FROM nginx:alpine

# Remove default config
RUN rm /etc/nginx/conf.d/default.conf

# Copy custom nginx configuration
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf

# Copy frontend build output (static files)
COPY assets/ /var/www/html/assets/
COPY index.html /var/www/html/index.html
COPY favicon.svg /var/www/html/favicon.svg

# PWA files
COPY manifest.json /var/www/html/manifest.json
COPY sw.js /var/www/html/sw.js
COPY apple-touch-icon.png /var/www/html/apple-touch-icon.png
COPY icons/ /var/www/html/icons/

# SEO files
COPY robots.txt /var/www/html/robots.txt
COPY sitemap.xml /var/www/html/sitemap.xml

# OG image (if exists)
COPY og-image.* /var/www/html/

# Backend PHP files (served via php-fpm)
COPY backend/ /var/www/html/backend/

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]

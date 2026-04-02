FROM nginx:alpine

# Remove default config
RUN rm /etc/nginx/conf.d/default.conf

# Copy custom nginx configuration
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf

# Copy frontend build output (static files)
COPY assets/ /var/www/html/assets/
COPY index.html /var/www/html/index.html
COPY favicon.svg /var/www/html/favicon.svg

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]

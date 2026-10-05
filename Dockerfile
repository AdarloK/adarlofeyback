ARG PHP_VERSION=8.3

FROM php:${PHP_VERSION}-apache

# CA certificates (needed for SSL connection to Aiven MySQL) + PDO MySQL
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Render provides the PORT environment variable (default 10000). Apache must listen on it.
ENV PORT=80
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf

# Copy app files
COPY . /var/www/html/

# Fix permissions (runtime/ must be writable for cache + rate limiting)
RUN mkdir -p /var/www/html/runtime/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/runtime

# Point Apache document root to public/
ENV APACHE_DOCUMENT_ROOT /var/www/html/public

RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot ${APACHE_DOCUMENT_ROOT}|g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|<Directory /var/www/html>|<Directory ${APACHE_DOCUMENT_ROOT}>|g' /etc/apache2/apache2.conf

EXPOSE 80

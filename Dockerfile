FROM php:8.2-apache

# Required PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Apache configuration
RUN a2enmod rewrite

# App directory
WORKDIR /var/www/html

# Copy bot.php
COPY bot.php /var/www/html/bot.php

# Create persistent data directory
RUN mkdir -p /data \
    && chown -R www-data:www-data /data \
    && chmod -R 755 /data

# Apache configuration
RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/app.conf \
    && a2enconf app

# Permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Persistent storage
VOLUME ["/data"]

EXPOSE 80

CMD ["apache2-foreground"]

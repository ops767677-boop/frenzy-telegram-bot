FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

COPY bot.php /var/www/html/bot.php

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && printf 'DirectoryIndex bot.php index.php index.html\n' > /etc/apache2/mods-enabled/dir.conf \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]

FROM php:8.2-apache

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

COPY bot.php /var/www/html/index.php

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]

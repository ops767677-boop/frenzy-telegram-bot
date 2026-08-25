FROM php:8.2-apache

WORKDIR /var/www/html

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 664 /var/www/html/*.json 2>/dev/null || true

EXPOSE 80

CMD ["apache2-foreground"]

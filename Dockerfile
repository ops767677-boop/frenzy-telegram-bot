FROM php:8.4-apache

RUN docker-php-ext-install pdo pdo_sqlite

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

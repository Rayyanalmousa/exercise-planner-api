FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql
RUN a2enmod rewrite

WORKDIR /var/www/html
COPY . /var/www/html

# Serve from /public
RUN sed -i 's#/var/www/html#/var/www/html/public#g' /etc/apache2/sites-available/000-default.conf

# Allow .htaccess rewrite rules
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

EXPOSE 80

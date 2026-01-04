FROM php:8.2-apache

# Install MySQL PDO
RUN docker-php-ext-install pdo pdo_mysql

# Enable rewrite
RUN a2enmod rewrite

# ✅ Fix: ensure only ONE MPM is enabled (prefork)
RUN a2dismod mpm_event mpm_worker || true \
    && a2enmod mpm_prefork

WORKDIR /var/www/html
COPY . /var/www/html

# Serve from /public
RUN sed -i 's#/var/www/html#/var/www/html/public#g' /etc/apache2/sites-available/000-default.conf

# Allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

EXPOSE 80

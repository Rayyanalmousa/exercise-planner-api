FROM php:8.2-fpm-alpine

# Install dependencies + pdo_mysql
RUN apk add --no-cache $PHPIZE_DEPS mariadb-connector-c-dev \
    && docker-php-ext-install pdo_mysql \
    && apk del $PHPIZE_DEPS

WORKDIR /app
COPY . /app

# Run PHP built-in server (no Apache)
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t public public/_router.php"]

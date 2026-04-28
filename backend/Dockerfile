FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git unzip openssl libicu-dev libzip-dev libpq-dev \
 && docker-php-ext-install intl zip opcache pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html/backend

RUN mkdir -p var/cache var/log vendor

COPY docker/php/prod-entrypoint.sh /usr/local/bin/sales-agent-php-entrypoint
RUN chmod +x /usr/local/bin/sales-agent-php-entrypoint

ENTRYPOINT ["/usr/local/bin/sales-agent-php-entrypoint"]
CMD ["php-fpm"]

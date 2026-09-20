# syntax=docker/dockerfile:1.7

FROM composer:2.7 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-interaction --no-scripts --no-autoloader \
    --prefer-dist --no-dev

COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM php:8.3-fpm-alpine AS runtime

RUN apk add --no-cache \
        bash \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        autoconf \
        g++ \
        make \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make linux-headers \
    && rm -rf /tmp/pear

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html

RUN chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm"]

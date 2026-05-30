FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    bash \
    curl \
    git \
    gmp-dev \
    icu-dev \
    icu-libs \
    libzip-dev \
    unzip

RUN docker-php-ext-configure intl && \
    docker-php-ext-install -j$(nproc) \
        gmp \
        intl \
        opcache \
        pdo \
        pdo_mysql \
        zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html

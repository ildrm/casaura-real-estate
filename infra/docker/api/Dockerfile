FROM composer:2.10.2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 AS composer

FROM php:8.5.8-fpm-alpine3.23@sha256:37554e891a1b1025c081cbd8bf28e3df38a11af807180f5e8c27315ef8aa9424 AS build

ARG PHPREDIS_VERSION=6.3.0
ARG PHPREDIS_SHA256=cb8f81df1a275599e4f8ddcfec7e1f65ed1953e6f5673649149fd680ebff4cad

RUN apk add --no-cache \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        postgresql-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd intl pcntl pdo_pgsql zip \
    && wget -q -O /tmp/phpredis.tar.gz "https://github.com/phpredis/phpredis/archive/refs/tags/${PHPREDIS_VERSION}.tar.gz" \
    && echo "${PHPREDIS_SHA256}  /tmp/phpredis.tar.gz" | sha256sum -c - \
    && mkdir /tmp/phpredis \
    && tar -xzf /tmp/phpredis.tar.gz -C /tmp/phpredis --strip-components=1 \
    && cd /tmp/phpredis \
    && phpize \
    && ./configure --enable-redis \
    && make -j"$(nproc)" \
    && make install \
    && docker-php-ext-enable redis \
    && cd / \
    && rm -rf /tmp/phpredis /tmp/phpredis.tar.gz \
    && apk del $PHPIZE_DEPS

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-progress --prefer-dist
COPY apps/api/ ./
RUN composer dump-autoload --classmap-authoritative --no-dev --no-interaction --no-scripts \
    && mkdir -p \
        bootstrap/cache \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache

FROM php:8.5.8-fpm-alpine3.23@sha256:37554e891a1b1025c081cbd8bf28e3df38a11af807180f5e8c27315ef8aa9424 AS runtime

RUN apk add --no-cache \
        freetype \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libpq \
        libzip \
    && addgroup -S -g 10001 casaura \
    && adduser -S -D -H -u 10001 -G casaura casaura

COPY --from=build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=build /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY infra/docker/api/php.ini /usr/local/etc/php/conf.d/99-casaura.ini
COPY infra/docker/api/php-fpm.conf /usr/local/etc/php-fpm.d/zz-casaura.conf
COPY --from=build --chown=casaura:casaura /app /app

WORKDIR /app
USER casaura
EXPOSE 9000
CMD ["php-fpm", "--nodaemonize"]

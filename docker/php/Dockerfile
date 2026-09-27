FROM php:8.4-cli-bookworm

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions pdo_mysql bcmath intl zip gd exif pcntl opcache \
    && apt-get update && apt-get install -y --no-install-recommends git unzip default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

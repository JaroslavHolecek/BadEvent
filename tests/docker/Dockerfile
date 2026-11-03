# PHP for running the tests: same major version line as the app requires (8.2+), with pdo_pgsql
# and Composer. mbstring, dom and xml (needed by the app and by PHPUnit) are built into the image.
FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip git \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /app/tests

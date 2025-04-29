FROM php:8.2-cli

RUN apt-get update && \
    apt-get install -y \
        zip \
        unzip \
        libzip-dev && \
    pecl install xdebug redis && \
    docker-php-ext-install zip && \
    docker-php-ext-enable xdebug && \
    docker-php-ext-enable redis

VOLUME /var/www/html

COPY --from=composer /usr/bin/composer /usr/bin/composer

CMD composer install && tail -f /dev/null
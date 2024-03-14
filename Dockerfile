FROM php:8.3-fpm-alpine

RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo_pgsql

COPY . /app

WORKDIR /app

# RUN chmod 777 log.txt

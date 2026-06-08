FROM php:8.2-cli-alpine AS base

RUN apk add --no-cache \
        linux-headers \
        rabbitmq-c-dev \
        postgresql-dev \
        $PHPIZE_DEPS \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && docker-php-ext-install pdo_pgsql sockets \
    && apk del $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY src/ src/
COPY bin/ bin/
COPY config/ config/
COPY migrations/ migrations/
COPY http/ http/

EXPOSE 8081

CMD ["sh", "bin/start.sh"]

FROM base AS dev

RUN composer install --optimize-autoloader --no-interaction

FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    protobuf-compiler \
    unzip \
    git \
    curl \
    && docker-php-ext-install pdo pdo_pgsql pcntl sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --optimize-autoloader --no-interaction

COPY . .

RUN curl -fsSL https://github.com/roadrunner-server/roadrunner/releases/download/v2025.1.12/roadrunner-2025.1.12-linux-amd64.tar.gz \
    -o /tmp/rr.tar.gz \
    && tar -xzf /tmp/rr.tar.gz -C /tmp \
    && mv /tmp/roadrunner-2025.1.12-linux-amd64/rr /usr/local/bin/rr \
    && chmod +x /usr/local/bin/rr \
    && rm -rf /tmp/rr.tar.gz /tmp/roadrunner-2025.1.12-linux-amd64

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]

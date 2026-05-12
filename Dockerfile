FROM dunglas/frankenphp:1-php8.4

RUN apt-get update && apt-get install -y \
    protobuf-compiler \
    unzip \
    git \
    curl \
    && install-php-extensions pdo_pgsql pcntl sockets redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --optimize-autoloader --no-interaction

COPY . .
COPY Caddyfile /etc/frankenphp/Caddyfile

RUN curl -fsSL https://github.com/roadrunner-server/roadrunner/releases/download/v2025.1.12/roadrunner-2025.1.12-linux-amd64.tar.gz \
    -o /tmp/rr.tar.gz \
    && tar -xzf /tmp/rr.tar.gz -C /tmp \
    && mv /tmp/roadrunner-2025.1.12-linux-amd64/rr /usr/local/bin/rr \
    && chmod +x /usr/local/bin/rr \
    && rm -rf /tmp/rr.tar.gz /tmp/roadrunner-2025.1.12-linux-amd64

EXPOSE 8080

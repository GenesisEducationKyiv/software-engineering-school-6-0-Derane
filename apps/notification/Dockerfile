# Build context is the REPO ROOT (RD3, set in docker-compose.yml) so this image can
# COPY the root-level proto/ and gen/ trees the gRPC server needs. The app is placed
# at /app/apps/notification (mirroring the repo) so the ../../gen PSR-4 paths and the
# ../../proto path in .rr.grpc.yaml resolve identically here, in the monolith gates
# container (-w /app/apps/notification), and on the host.
FROM php:8.2-cli-alpine AS base

RUN apk add --no-cache \
        linux-headers \
        postgresql-dev \
        curl \
        $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql sockets pcntl \
    && apk del $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/*

# RoadRunner binary for the Service B gRPC server (:9002). RoadRunner's Go process
# terminates gRPC and hands decoded messages to the pure-PHP Spiral worker, so no
# ext-grpc is needed here (mirrors the monolith grpc service). Pinned to match the
# root image.
RUN curl -fsSL https://github.com/roadrunner-server/roadrunner/releases/download/v2025.1.12/roadrunner-2025.1.12-linux-amd64.tar.gz \
        -o /tmp/rr.tar.gz \
    && tar -xzf /tmp/rr.tar.gz -C /tmp \
    && mv /tmp/roadrunner-2025.1.12-linux-amd64/rr /usr/local/bin/rr \
    && chmod +x /usr/local/bin/rr \
    && rm -rf /tmp/rr.tar.gz /tmp/roadrunner-2025.1.12-linux-amd64

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN addgroup -S app && adduser -S app -G app

WORKDIR /app/apps/notification

COPY apps/notification/composer.json apps/notification/composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=ext-grpc

COPY apps/notification/src/ src/
COPY apps/notification/bin/ bin/
COPY apps/notification/config/ config/
COPY apps/notification/migrations/ migrations/
COPY apps/notification/http/ http/
COPY apps/notification/.rr.grpc.yaml ./

# Repo-root proto contract + buf-generated stubs the gRPC server needs at build and
# runtime (RD3). Placed at /app/proto and /app/gen so ../../proto (.rr.grpc.yaml) and
# ../../gen (PSR-4) resolve from the WORKDIR.
COPY proto/ /app/proto/
COPY gen/ /app/gen/

# Re-dump the optimized autoloader now that src/ and the generated gen/ tree exist.
RUN composer dump-autoload --no-dev --optimize

RUN chown -R app:app /app

USER app

EXPOSE 8081 9002

CMD ["sh", "bin/start.sh"]

FROM base AS dev

USER root
RUN composer install --optimize-autoloader --no-interaction --ignore-platform-reqs
USER app

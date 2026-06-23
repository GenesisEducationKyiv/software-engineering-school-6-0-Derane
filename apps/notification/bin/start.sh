#!/bin/sh
set -e

# Apply schema before serving. Migrations are idempotent (CREATE/ADD ... IF NOT
# EXISTS), so re-running on every boot is safe and avoids a first-boot crash
# loop when the notification-db volume is fresh.
php bin/migrate.php

php -S 0.0.0.0:8081 http/server.php &

# NEW (REST→gRPC migration): the welcome-email gRPC unary server on :9002,
# plaintext over the compose network. Forked alongside the HTTP server; under
# `set -e` it runs unsupervised (the /health probe covers :8081 only) — an
# accepted risk recorded in ADR-0004.
rr serve -c .rr.grpc.yaml &

exec php bin/consumer.php

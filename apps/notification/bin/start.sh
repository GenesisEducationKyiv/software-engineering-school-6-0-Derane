#!/bin/sh
set -e

# Apply schema before serving. Migrations are idempotent (CREATE/ADD ... IF NOT
# EXISTS), so re-running on every boot is safe and avoids a first-boot crash
# loop when the notification-db volume is fresh.
php bin/migrate.php

php -S 0.0.0.0:8081 http/server.php &

exec php bin/consumer.php

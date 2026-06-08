#!/bin/sh
set -e

php -S 0.0.0.0:8081 http/server.php &

exec php bin/consumer.php

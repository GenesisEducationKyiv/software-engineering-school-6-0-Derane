# Notification Service Cutover Runbook

## Purpose

Step-by-step deployment sequence for Epic E4 (monolith decommission). Applying schema
changes before code changes causes runtime errors.

## Pre-conditions

- [ ] Docker stack running and healthy (`make up`)
- [ ] CI passing on the E4 branch (`make ci`)
- [ ] Rollback plan: keep `E3` tag pinned before proceeding

## Deployment order

### Step 1 — Deploy E4 code

Deploy the application code (monolith + notification service) containing:
- Deleted `NotificationDispatcher`, `NotifierService`, monolith `NotificationLedger`
- Cleaned `config/container.php` (no legacy bindings)

Verify:
```bash
composer psalm          # 0 errors
composer lint           # 0 violations
./vendor/bin/phpunit --no-coverage --testsuite Unit
```

### Step 2 — Smoke-test the running stack

```bash
make scanner-smoke      # scanner publishes via RabbitMQ, service delivers
make resilience-proof   # scenario A + B pass
```

### Step 3 — Apply migration 003

Only after Step 2 passes:

```bash
# Monolith database
psql -U "$PGUSER" -d "$PGDB" -f migrations/003_drop_release_notifications.sql
```

### Step 4 — Verify

```bash
make scanner-smoke      # still green after table drop
```

## Rollback

If Step 3 fails:
1. Restore table: `psql ... -c "CREATE TABLE release_notifications (...)"`
2. Roll back to E3 tag
3. Redeploy

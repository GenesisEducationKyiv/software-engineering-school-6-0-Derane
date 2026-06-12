# GDPR Data Retention Policy — Notification Service

## Data stored

The notification service's `release_notifications` ledger stores:

| Column | Data class | Purpose |
|---|---|---|
| `subscription_id` | Pseudonymous reference | Links delivery record to the monolith subscription |
| `email` | Personal data (PII) | Deduplicate: prevents re-sending to the same address for the same release |
| `repository` | Non-personal | Repository identifier |
| `tag_name` | Non-personal | Release tag (idempotency key) |
| `sent_at` | Non-personal | Timestamp |

## Retention period

Ledger rows are retained for **90 days** from `sent_at`. Rows older than 90 days serve no
deduplication purpose and are purged by the retention script.

**Implementation:** `apps/notification/bin/purge.php` — run as a nightly cron job:

```bash
# crontab entry (nightly at 03:00)
0 3 * * * docker compose exec -T notification-svc php bin/purge.php
```

The script deletes all rows where `sent_at < NOW() - INTERVAL '90 days'` and logs the
count of deleted rows to stdout. The retention window is configurable via `RETENTION_DAYS`
environment variable (default: 90).

## Right to erasure (GDPR Art. 17)

When a subscriber exercises the right to erasure:

1. The monolith deletes the `subscriptions` row (cascades to `subscriptions`-owned data).
2. The notification service ledger retains rows keyed by `subscription_id` as an integer
   reference. Because there is no foreign-key constraint after E4, these rows are **orphaned**
   but are purged within the 90-day retention window above.
3. For immediate erasure, run:

```sql
DELETE FROM release_notifications WHERE subscription_id = :id;
```

## Future work

A `SubscriptionDeleted` domain event (out of scope for HW7) will enable real-time cascade
deletion from the notification ledger, eliminating the 90-day orphan window.

-- SAFETY: Apply ONLY AFTER Epic E4 code deletion is deployed and verified.
-- Deployment order: deploy E4 code → run composer lint/psalm/phpunit → apply this migration.
-- Applying before E4 code is live leaves monolith DI referencing a non-existent table.
DROP TABLE IF EXISTS release_notifications;


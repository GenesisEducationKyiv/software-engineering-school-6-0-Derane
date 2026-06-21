ALTER TABLE subscriptions
    ADD COLUMN IF NOT EXISTS status VARCHAR(16) NOT NULL DEFAULT 'pending';

CREATE INDEX IF NOT EXISTS idx_subscriptions_repository_status
    ON subscriptions(repository, status);

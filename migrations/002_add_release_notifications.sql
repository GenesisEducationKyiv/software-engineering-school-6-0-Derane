CREATE TABLE IF NOT EXISTS release_notifications (
    id SERIAL PRIMARY KEY,
    subscription_id INTEGER NOT NULL REFERENCES subscriptions(id) ON DELETE CASCADE,
    repository VARCHAR(255) NOT NULL,
    tag_name VARCHAR(255) NOT NULL,
    sent_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    UNIQUE(subscription_id, repository, tag_name)
);

CREATE INDEX IF NOT EXISTS idx_repositories_last_checked_at ON repositories(last_checked_at);
CREATE INDEX IF NOT EXISTS idx_release_notifications_lookup
    ON release_notifications(subscription_id, repository, tag_name);

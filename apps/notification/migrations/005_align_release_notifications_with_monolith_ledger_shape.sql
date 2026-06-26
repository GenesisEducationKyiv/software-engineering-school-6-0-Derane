ALTER TABLE release_notifications
    ADD COLUMN IF NOT EXISTS attempts   INTEGER                  NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW();

ALTER TABLE release_notifications
    ALTER COLUMN email DROP NOT NULL,
    ALTER COLUMN subscription_id TYPE INTEGER USING subscription_id::INTEGER,
    ALTER COLUMN tag_name TYPE VARCHAR(255);

ALTER TABLE release_notifications
    DROP CONSTRAINT IF EXISTS uq_release_notification,
    ADD CONSTRAINT uq_release_notification UNIQUE (subscription_id, repository, tag_name);

CREATE INDEX IF NOT EXISTS idx_release_notifications_lookup
    ON release_notifications(subscription_id, repository, tag_name);

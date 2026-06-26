CREATE TABLE IF NOT EXISTS release_notifications (
    id              SERIAL                    PRIMARY KEY,
    subscription_id INTEGER                   NOT NULL,
    repository      VARCHAR(255)              NOT NULL,
    tag_name        VARCHAR(255)              NOT NULL,
    email           VARCHAR(320),
    sent_at         TIMESTAMP WITH TIME ZONE  DEFAULT NULL,
    attempts        INTEGER                   NOT NULL DEFAULT 0,
    last_error      TEXT                      DEFAULT NULL,
    created_at      TIMESTAMP WITH TIME ZONE  DEFAULT NOW(),
    updated_at      TIMESTAMP WITH TIME ZONE  DEFAULT NOW(),
    attempt_count   INTEGER                   NOT NULL DEFAULT 0,
    claimed_at      TIMESTAMP WITH TIME ZONE,
    claim_token     VARCHAR(64),
    CONSTRAINT uq_release_notification UNIQUE (subscription_id, repository, tag_name)
);

CREATE INDEX IF NOT EXISTS idx_release_notifications_lookup
    ON release_notifications(subscription_id, repository, tag_name);

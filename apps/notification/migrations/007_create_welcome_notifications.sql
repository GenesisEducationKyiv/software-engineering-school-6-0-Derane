CREATE TABLE IF NOT EXISTS welcome_notifications (
    id                 SERIAL                   PRIMARY KEY,
    subscription_id    INTEGER                  NOT NULL,
    email              VARCHAR(320),
    sent_at            TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    terminal_failed_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    last_error         TEXT                     DEFAULT NULL,
    attempt_count      INTEGER                  NOT NULL DEFAULT 0,
    claimed_at         TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    claim_token        VARCHAR(64),
    created_at         TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at         TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    CONSTRAINT uq_welcome_notification UNIQUE (subscription_id)
);

CREATE INDEX IF NOT EXISTS idx_welcome_notifications_lookup
    ON welcome_notifications(subscription_id);

CREATE TABLE IF NOT EXISTS release_notifications (
    id              BIGSERIAL       PRIMARY KEY,
    subscription_id BIGINT          NOT NULL,
    repository      VARCHAR(255)    NOT NULL,
    tag_name        VARCHAR(100)    NOT NULL,
    email           VARCHAR(320)    NOT NULL,
    sent_at         TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_release_notification UNIQUE (subscription_id, tag_name, repository)
);

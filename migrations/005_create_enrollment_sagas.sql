CREATE TABLE IF NOT EXISTS enrollment_sagas (
    id               SERIAL                   PRIMARY KEY,
    saga_id          UUID                     NOT NULL UNIQUE,
    subscription_id  INTEGER                  NOT NULL UNIQUE,
    state            VARCHAR(32)              NOT NULL DEFAULT 'started',
    awaiting_since   TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    attempts         INTEGER                  NOT NULL DEFAULT 0,
    last_error       TEXT                     DEFAULT NULL,
    created_at       TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at       TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_enrollment_sagas_relay
    ON enrollment_sagas(state, created_at);
CREATE INDEX IF NOT EXISTS idx_enrollment_sagas_sweep
    ON enrollment_sagas(state, awaiting_since);

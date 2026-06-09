-- Atomic-claim support: claimed_at marks a notification as in flight, and
-- claim_token fences markSent/recordFailedAttempt so a worker whose lease
-- expired (and was re-claimed) cannot overwrite the new holder's state.
-- States: sent_at NOT NULL = sent; claimed_at NOT NULL = claimed (in flight);
-- both NULL = failed / never attempted (claimable).
-- Existing rows keep both NULL: sent rows stay sent, failed rows stay
-- retryable. Idempotent — the migration runner re-applies every file.
ALTER TABLE release_notifications
    ADD COLUMN IF NOT EXISTS claimed_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS claim_token VARCHAR(64);

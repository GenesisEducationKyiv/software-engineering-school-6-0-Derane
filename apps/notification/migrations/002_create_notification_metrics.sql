CREATE TABLE IF NOT EXISTS notification_metrics (
    metric_name VARCHAR(64) PRIMARY KEY,
    metric_value BIGINT NOT NULL DEFAULT 0
);

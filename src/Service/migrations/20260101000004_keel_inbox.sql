-- Keel: events already handled, for exactly-once processing of at-least-once delivery (->withServices()).
CREATE TABLE keel_inbox (
    event_key   VARCHAR(64)  PRIMARY KEY,
    source      VARCHAR(255) NOT NULL,
    event_id    VARCHAR(255) NOT NULL,
    type        VARCHAR(255) NOT NULL,
    received_at BIGINT       NOT NULL
);
CREATE INDEX keel_inbox_received ON keel_inbox (received_at);

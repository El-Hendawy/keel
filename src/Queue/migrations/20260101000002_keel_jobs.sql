-- Keel: background jobs and the transactional outbox (->withQueue()).
CREATE TABLE keel_jobs (
    id             VARCHAR(36)  PRIMARY KEY,
    queue          VARCHAR(64)  NOT NULL,
    type           VARCHAR(255) NOT NULL,
    payload        TEXT         NOT NULL,
    tenant_id      VARCHAR(255) NULL,
    traceparent    VARCHAR(64)  NULL,
    attempts       INTEGER      NOT NULL DEFAULT 0,
    status         VARCHAR(16)  NOT NULL,
    available_at   BIGINT       NOT NULL,
    reserved_until BIGINT       NULL,
    reservation    VARCHAR(36)  NULL,
    last_error     TEXT         NULL,
    created_at     BIGINT       NOT NULL,
    failed_at      BIGINT       NULL
);
CREATE INDEX keel_jobs_ready ON keel_jobs (queue, status, available_at);

-- Distributed locks (scheduler, cache stampede protection) when Redis is not used.
CREATE TABLE keel_locks (
    name       VARCHAR(255) PRIMARY KEY,
    token      VARCHAR(64)  NOT NULL,
    expires_at BIGINT       NOT NULL
);

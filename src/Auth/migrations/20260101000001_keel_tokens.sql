-- Keel: issued API tokens and session tokens (->withAuth()). Only SHA-256 hashes of secrets are stored.
CREATE TABLE keel_tokens (
    id                VARCHAR(36)  PRIMARY KEY,
    hash              VARCHAR(64)  NOT NULL UNIQUE,
    principal_id      VARCHAR(255) NOT NULL,
    tenant_id         VARCHAR(255) NULL,
    scopes            TEXT         NOT NULL,
    kind              VARCHAR(16)  NOT NULL,
    fingerprint       TEXT         NOT NULL,
    bind_on_first_use SMALLINT     NOT NULL DEFAULT 0,
    name              VARCHAR(255) NOT NULL DEFAULT '',
    created_at        BIGINT       NOT NULL,
    expires_at        BIGINT       NULL,
    revoked_at        BIGINT       NULL
);
CREATE INDEX keel_tokens_principal ON keel_tokens (principal_id);

-- Keel: an append-only, hash-chained, double-entry ledger (->withLedger()).
CREATE TABLE keel_ledger_transactions (
    id              VARCHAR(36)  PRIMARY KEY,
    seq             BIGINT       NOT NULL UNIQUE,
    idempotency_key VARCHAR(255) NULL UNIQUE,
    tenant_id       VARCHAR(255) NULL,
    description     TEXT         NOT NULL,
    metadata        TEXT         NOT NULL,
    posted_at       BIGINT       NOT NULL,
    previous_hash   VARCHAR(64)  NOT NULL,
    hash            VARCHAR(64)  NOT NULL
);

-- One row per leg. Amounts are signed minor units: debits positive, credits negative.
CREATE TABLE keel_ledger_entries (
    id             VARCHAR(36)  PRIMARY KEY,
    transaction_id VARCHAR(36)  NOT NULL,
    leg            INTEGER      NOT NULL,
    account        VARCHAR(255) NOT NULL,
    currency       VARCHAR(3)   NOT NULL,
    amount         BIGINT       NOT NULL,
    tenant_id      VARCHAR(255) NULL
);
CREATE INDEX keel_ledger_entries_account ON keel_ledger_entries (account, currency);
CREATE INDEX keel_ledger_entries_transaction ON keel_ledger_entries (transaction_id);

-- The end of the chain; posting locks this row, so transactions are numbered without gaps.
CREATE TABLE keel_ledger_head (
    id   INTEGER     PRIMARY KEY,
    seq  BIGINT      NOT NULL,
    hash VARCHAR(64) NOT NULL
);
INSERT INTO keel_ledger_head (id, seq, hash) VALUES (1, 0, '0000000000000000000000000000000000000000000000000000000000000000');

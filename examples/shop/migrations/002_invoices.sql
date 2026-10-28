CREATE TABLE invoices (
    id           VARCHAR(36) PRIMARY KEY,
    tenant_id    VARCHAR(36) NOT NULL,
    order_id     VARCHAR(36) NOT NULL UNIQUE,
    amount_cents INTEGER NOT NULL,
    status       VARCHAR(16) NOT NULL,
    created_at   VARCHAR(40) NOT NULL
);

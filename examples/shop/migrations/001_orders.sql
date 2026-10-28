CREATE TABLE orders (
    id          VARCHAR(36) PRIMARY KEY,
    tenant_id   VARCHAR(36) NOT NULL,
    merchant_id VARCHAR(36) NOT NULL,
    status      VARCHAR(16) NOT NULL,
    note        TEXT NULL,
    total_cents INTEGER NOT NULL,
    created_at  VARCHAR(40) NOT NULL
);
CREATE INDEX orders_tenant ON orders (tenant_id, created_at);

CREATE TABLE order_items (
    order_id         VARCHAR(36) NOT NULL REFERENCES orders (id),
    sku              VARCHAR(64) NOT NULL,
    quantity         INTEGER NOT NULL,
    unit_price_cents INTEGER NOT NULL
);
CREATE INDEX order_items_order ON order_items (order_id);

-- Catalog products, mapped by the Shop\Catalog\Product entity. Started from `keel orm:diff --write`
-- (which writes the connected database's dialect) and made portable across SQLite, PostgreSQL and MySQL.
CREATE TABLE products (
    id          VARCHAR(36)  PRIMARY KEY,
    tenant_id   VARCHAR(255) NOT NULL,
    sku         VARCHAR(64)  NOT NULL,
    name        VARCHAR(255) NOT NULL,
    price_cents INTEGER      NOT NULL,
    active      BOOLEAN      NOT NULL,
    version     INTEGER      NOT NULL,
    created_at  VARCHAR(32)  NULL,
    updated_at  VARCHAR(32)  NULL,
    deleted_at  VARCHAR(32)  NULL
);
CREATE UNIQUE INDEX products_tenant_sku ON products (tenant_id, sku);

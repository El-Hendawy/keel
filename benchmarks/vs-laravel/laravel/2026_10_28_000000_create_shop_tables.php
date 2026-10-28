<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Identical schema to the Keel example (examples/shop/migrations).
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE orders (
                id VARCHAR(36) PRIMARY KEY, tenant_id VARCHAR(36) NOT NULL, merchant_id VARCHAR(36) NOT NULL,
                status VARCHAR(16) NOT NULL, note TEXT NULL, total_cents INTEGER NOT NULL, created_at VARCHAR(40) NOT NULL
            );
            CREATE INDEX orders_tenant ON orders (tenant_id, created_at);
            CREATE TABLE order_items (
                order_id VARCHAR(36) NOT NULL REFERENCES orders (id), position INTEGER NOT NULL DEFAULT 0, sku VARCHAR(64) NOT NULL,
                quantity INTEGER NOT NULL, unit_price_cents INTEGER NOT NULL
            );
            CREATE INDEX order_items_order ON order_items (order_id);
            CREATE TABLE invoices (
                id VARCHAR(36) PRIMARY KEY, tenant_id VARCHAR(36) NOT NULL, order_id VARCHAR(36) NOT NULL UNIQUE,
                amount_cents INTEGER NOT NULL, status VARCHAR(16) NOT NULL, created_at VARCHAR(40) NOT NULL
            );
            SQL);
    }
};

-- Keeps line order without relying on SQLite's rowid, so the schema runs on PostgreSQL and MySQL too.
ALTER TABLE order_items ADD COLUMN position INTEGER NOT NULL DEFAULT 0;

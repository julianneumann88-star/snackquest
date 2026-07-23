-- Per-product row locks serialize competing offline revisions before conflict checks.
CREATE TABLE IF NOT EXISTS {{prefix}}sync_locks (
  user_id INTEGER NOT NULL REFERENCES {{prefix}}users(id) ON DELETE CASCADE,
  product_key TEXT NOT NULL,
  touched_at TEXT NOT NULL,
  PRIMARY KEY(user_id, product_key)
);

-- Persistent, user-scoped idempotency receipts for offline review synchronization.
CREATE TABLE IF NOT EXISTS {{prefix}}sync_receipts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES {{prefix}}users(id) ON DELETE CASCADE,
  sync_id TEXT NOT NULL,
  entry_id INTEGER NOT NULL REFERENCES {{prefix}}user_product_entries(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL,
  completed_at TEXT NOT NULL,
  UNIQUE(user_id, sync_id)
);
CREATE INDEX IF NOT EXISTS {{prefix}}idx_sync_receipt_created ON {{prefix}}sync_receipts(created_at);

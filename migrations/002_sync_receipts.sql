-- Per-product row locks serialize competing offline revisions before conflict checks.
CREATE TABLE IF NOT EXISTS {{prefix}}sync_locks (
  user_id INT UNSIGNED NOT NULL,
  product_key VARCHAR(80) NOT NULL,
  touched_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, product_key),
  CONSTRAINT fk_sq_sync_lock_user FOREIGN KEY (user_id) REFERENCES {{prefix}}users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Persistent, user-scoped idempotency receipts for offline review synchronization.
CREATE TABLE IF NOT EXISTS {{prefix}}sync_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  sync_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  entry_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  completed_at DATETIME NOT NULL,
  UNIQUE KEY uq_sq_sync_receipt (user_id, sync_id),
  INDEX idx_sq_sync_receipt_created (created_at),
  CONSTRAINT fk_sq_sync_receipt_user FOREIGN KEY (user_id) REFERENCES {{prefix}}users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sq_sync_receipt_entry FOREIGN KEY (entry_id) REFERENCES {{prefix}}user_product_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

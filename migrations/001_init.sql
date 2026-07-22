-- SnackQuest 001_init (MariaDB). {{prefix}} is replaced by bin/migrate.php.
CREATE TABLE IF NOT EXISTS {{prefix}}users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  email_verified_at DATETIME NULL,
  password_hash VARCHAR(255) NULL,
  google_sub VARCHAR(64) NULL UNIQUE,
  display_name VARCHAR(80) NOT NULL DEFAULT '',
  avatar_url VARCHAR(500) NOT NULL DEFAULT '',
  locale VARCHAR(8) NOT NULL DEFAULT 'de',
  timezone VARCHAR(48) NOT NULL DEFAULT 'Europe/Berlin',
  country VARCHAR(2) NOT NULL DEFAULT 'DE',
  onboarding_completed TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}auth_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_type VARCHAR(16) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_auth_token (token_type, token_hash),
  CONSTRAINT fk_sq_token_user FOREIGN KEY (user_id) REFERENCES {{prefix}}users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}rate_limits (
  rl_key CHAR(64) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (rl_key, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}user_preferences (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  taste_preferences TEXT NULL,
  favorite_categories TEXT NULL,
  excluded_categories TEXT NULL,
  default_sort VARCHAR(24) NOT NULL DEFAULT 'recent',
  ai_opt_in TINYINT(1) NOT NULL DEFAULT 0,
  analytics_opt_in TINYINT(1) NOT NULL DEFAULT 0,
  notifications TEXT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_sq_prefs_user FOREIGN KEY (user_id) REFERENCES {{prefix}}users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}product_cache (
  barcode VARCHAR(32) NOT NULL PRIMARY KEY,
  product_json MEDIUMTEXT NULL,
  source_version VARCHAR(16) NOT NULL DEFAULT 'v3.6',
  source_url VARCHAR(700) NOT NULL DEFAULT '',
  source_updated_at DATETIME NULL,
  last_fetched_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  fetch_status VARCHAR(20) NOT NULL DEFAULT 'ok',
  INDEX idx_sq_product_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}custom_products (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  barcode VARCHAR(32) NULL,
  name VARCHAR(240) NOT NULL,
  brand VARCHAR(160) NOT NULL DEFAULT '',
  category VARCHAR(160) NOT NULL DEFAULT '',
  quantity VARCHAR(80) NOT NULL DEFAULT '',
  image_path VARCHAR(500) NOT NULL DEFAULT '',
  note VARCHAR(1200) NOT NULL DEFAULT '',
  visibility VARCHAR(12) NOT NULL DEFAULT 'private',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_sq_custom_owner (owner_user_id, updated_at),
  CONSTRAINT fk_sq_custom_user FOREIGN KEY (owner_user_id) REFERENCES {{prefix}}users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}user_product_entries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  product_key VARCHAR(80) NOT NULL,
  barcode VARCHAR(32) NULL,
  custom_product_id INT UNSIGNED NULL,
  product_name VARCHAR(240) NOT NULL,
  brand VARCHAR(160) NOT NULL DEFAULT '',
  category VARCHAR(160) NOT NULL DEFAULT '',
  image_url VARCHAR(700) NOT NULL DEFAULT '',
  overall_rating TINYINT UNSIGNED NOT NULL,
  taste_rating TINYINT UNSIGNED NULL,
  texture_rating TINYINT UNSIGNED NULL,
  value_rating TINYINT UNSIGNED NULL,
  packaging_rating TINYINT UNSIGNED NULL,
  portion_rating TINYINT UNSIGNED NULL,
  buy_again VARCHAR(8) NOT NULL DEFAULT 'maybe',
  favorite TINYINT(1) NOT NULL DEFAULT 0,
  never_again TINYINT(1) NOT NULL DEFAULT 0,
  movie_night TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(3000) NOT NULL DEFAULT '',
  first_tried_at DATETIME NOT NULL,
  last_tried_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_sq_user_product (user_id, product_key),
  INDEX idx_sq_entry_user_rating (user_id, overall_rating),
  INDEX idx_sq_entry_user_flags (user_id, favorite, buy_again, never_again),
  CONSTRAINT fk_sq_entry_user FOREIGN KEY (user_id) REFERENCES {{prefix}}users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sq_entry_custom FOREIGN KEY (custom_product_id) REFERENCES {{prefix}}custom_products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}taste_tags (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(48) NOT NULL UNIQUE,
  label VARCHAR(80) NOT NULL,
  category VARCHAR(32) NOT NULL DEFAULT 'taste'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}user_product_tags (
  entry_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (entry_id, tag_id),
  CONSTRAINT fk_sq_e_tag_entry FOREIGN KEY (entry_id) REFERENCES {{prefix}}user_product_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_sq_e_tag_tag FOREIGN KEY (tag_id) REFERENCES {{prefix}}taste_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}stores (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  city VARCHAR(120) NOT NULL DEFAULT '',
  country VARCHAR(2) NOT NULL DEFAULT 'DE',
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_sq_store (user_id, name, city),
  CONSTRAINT fk_sq_store_user FOREIGN KEY (user_id) REFERENCES {{prefix}}users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}price_entries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  product_key VARCHAR(80) NOT NULL,
  price DECIMAL(8,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  quantity_text VARCHAR(80) NOT NULL DEFAULT '',
  store_id INT UNSIGNED NULL,
  store_name_snapshot VARCHAR(160) NOT NULL DEFAULT '',
  purchased_at DATE NOT NULL,
  receipt_photo_path VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX idx_sq_price_user_product (user_id, product_key, purchased_at),
  CONSTRAINT fk_sq_price_user FOREIGN KEY (user_id) REFERENCES {{prefix}}users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sq_price_store FOREIGN KEY (store_id) REFERENCES {{prefix}}stores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}review_photos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  entry_id INT UNSIGNED NOT NULL,
  storage_path VARCHAR(500) NOT NULL,
  width INT UNSIGNED NOT NULL DEFAULT 0,
  height INT UNSIGNED NOT NULL DEFAULT 0,
  mime_type VARCHAR(40) NOT NULL,
  alt_text VARCHAR(240) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX idx_sq_photo_entry (entry_id),
  CONSTRAINT fk_sq_photo_user FOREIGN KEY (user_id) REFERENCES {{prefix}}users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sq_photo_entry FOREIGN KEY (entry_id) REFERENCES {{prefix}}user_product_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}collections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(600) NOT NULL DEFAULT '',
  visibility VARCHAR(12) NOT NULL DEFAULT 'private',
  share_token_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_sq_collection_user (user_id, updated_at),
  CONSTRAINT fk_sq_collection_user FOREIGN KEY (user_id) REFERENCES {{prefix}}users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}collection_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  collection_id INT UNSIGNED NOT NULL,
  product_key VARCHAR(80) NOT NULL,
  product_name VARCHAR(240) NOT NULL,
  image_url VARCHAR(700) NOT NULL DEFAULT '',
  position INT UNSIGNED NOT NULL DEFAULT 0,
  added_at DATETIME NOT NULL,
  UNIQUE KEY uq_sq_collection_item (collection_id, product_key),
  CONSTRAINT fk_sq_collection_item_parent FOREIGN KEY (collection_id) REFERENCES {{prefix}}collections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}battle_sessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  battle_type VARCHAR(32) NOT NULL DEFAULT 'taste',
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  CONSTRAINT fk_sq_battle_user FOREIGN KEY (user_id) REFERENCES {{prefix}}users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}battle_pairs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  battle_session_id INT UNSIGNED NOT NULL,
  left_product_key VARCHAR(80) NOT NULL,
  right_product_key VARCHAR(80) NOT NULL,
  winner_product_key VARCHAR(80) NULL,
  selection_reason VARCHAR(32) NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_sq_pair_session FOREIGN KEY (battle_session_id) REFERENCES {{prefix}}battle_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}ranking_scores (
  user_id INT UNSIGNED NOT NULL,
  product_key VARCHAR(80) NOT NULL,
  dimension VARCHAR(32) NOT NULL,
  score DECIMAL(8,2) NOT NULL DEFAULT 1000,
  match_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, product_key, dimension),
  CONSTRAINT fk_sq_rank_user FOREIGN KEY (user_id) REFERENCES {{prefix}}users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}quests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  quest_type VARCHAR(40) NOT NULL,
  title VARCHAR(240) NOT NULL,
  parameters TEXT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  progress INT UNSIGNED NOT NULL DEFAULT 0,
  target INT UNSIGNED NOT NULL DEFAULT 1,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  completed_at DATETIME NULL,
  INDEX idx_sq_quest_user (user_id, status, starts_at),
  CONSTRAINT fk_sq_quest_user FOREIGN KEY (user_id) REFERENCES {{prefix}}users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}shares (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  share_type VARCHAR(24) NOT NULL,
  resource_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  payload TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  CONSTRAINT fk_sq_share_user FOREIGN KEY (user_id) REFERENCES {{prefix}}users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}ai_insights (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  insight_type VARCHAR(32) NOT NULL,
  structured_input_hash CHAR(64) NOT NULL,
  result TEXT NOT NULL,
  model VARCHAR(100) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  CONSTRAINT fk_sq_ai_user FOREIGN KEY (user_id) REFERENCES {{prefix}}users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}audit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  event_type VARCHAR(48) NOT NULL,
  request_id VARCHAR(32) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX idx_sq_audit_user (user_id, created_at),
  CONSTRAINT fk_sq_audit_user FOREIGN KEY (user_id) REFERENCES {{prefix}}users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prefix}}settings (
  s_key VARCHAR(80) NOT NULL PRIMARY KEY,
  s_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


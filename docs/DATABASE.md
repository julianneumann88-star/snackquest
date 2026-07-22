# Datenbank

Produktion: MariaDB, InnoDB, `utf8mb4`, Tabellenpräfix `sq_`, echte Foreign Keys und vorbereitete PDO-Statements. Tests nutzen eine schemagleiche SQLite-Variante.

```mermaid
erDiagram
  USERS ||--o| USER_PREFERENCES : has
  USERS ||--o{ CUSTOM_PRODUCTS : owns
  USERS ||--o{ USER_PRODUCT_ENTRIES : rates
  USER_PRODUCT_ENTRIES ||--o{ PRICE_ENTRIES : records
  USER_PRODUCT_ENTRIES ||--o{ REVIEW_PHOTOS : has
  USER_PRODUCT_ENTRIES ||--o{ USER_PRODUCT_TAGS : tagged
  USERS ||--o{ COLLECTIONS : owns
  COLLECTIONS ||--o{ COLLECTION_ITEMS : contains
  USERS ||--o{ BATTLE_SESSIONS : starts
  USERS ||--o{ RANKING_SCORES : ranks
  USERS ||--o{ QUESTS : receives
  USERS ||--o{ SHARES : creates
  USERS ||--o{ AI_INSIGHTS : requests
```

Migrationen sind additiv und werden über `sq_migrations` verfolgt. `bin/migrate.php` ist idempotent. Löschung eines Nutzers kaskadiert alle privaten Relationen; Uploaddateien werden danach aus dem privaten Storage entfernt. Produktcache ist nicht nutzerbezogen.

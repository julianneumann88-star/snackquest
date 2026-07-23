-- A battle session may produce at most one persisted decision.
-- Keep the earliest canonical result if an older deployment admitted a race.
-- After this file, bin/migrate.php transactionally rebuilds all ranking scores
-- from the remaining canonical pairs before recording the migration receipt.
DELETE duplicate_pair
FROM {{prefix}}battle_pairs AS duplicate_pair
INNER JOIN {{prefix}}battle_pairs AS canonical_pair
  ON canonical_pair.battle_session_id = duplicate_pair.battle_session_id
 AND canonical_pair.id < duplicate_pair.id;

-- MariaDB DDL auto-commits. The information_schema guard makes a retry safe if
-- the index was created but writing the migration receipt was interrupted.
SET @sq_battle_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}battle_pairs'
    AND INDEX_NAME = 'uq_sq_battle_pair_session'
);
SET @sq_battle_index_sql = IF(
  @sq_battle_index_exists = 0,
  'ALTER TABLE {{prefix}}battle_pairs ADD UNIQUE KEY uq_sq_battle_pair_session (battle_session_id)',
  'SELECT 1'
);
PREPARE sq_battle_index_statement FROM @sq_battle_index_sql;
EXECUTE sq_battle_index_statement;
DEALLOCATE PREPARE sq_battle_index_statement;

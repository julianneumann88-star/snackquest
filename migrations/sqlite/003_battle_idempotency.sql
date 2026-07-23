-- A battle session may produce at most one persisted decision.
-- After this file, bin/migrate.php transactionally rebuilds all ranking scores
-- from the remaining canonical pairs before recording the migration receipt.
DELETE FROM {{prefix}}battle_pairs
WHERE id NOT IN (
  SELECT MIN(id)
  FROM {{prefix}}battle_pairs
  GROUP BY battle_session_id
);
CREATE UNIQUE INDEX IF NOT EXISTS {{prefix}}uq_battle_pair_session
  ON {{prefix}}battle_pairs(battle_session_id);

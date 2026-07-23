<?php
/**
 * SnackQuest — migration runner (CLI).
 * Usage: php bin/migrate.php [--dry-run] [--config=/path/to/config.local.php]
 * Applies pending migrations from migrations/ (mysql) or migrations/sqlite/ (sqlite),
 * tracked in {prefix}migrations. Idempotent: applied files are skipped.
 * Exit codes: 0 ok · 1 runtime error · 2 config/usage error.
 * Rollback: schema is additive (CREATE TABLE IF NOT EXISTS); restore via mysqldump backup
 * documented in docs/BACKUP_RESTORE.md.
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

use SnackQuest\App;
use SnackQuest\Database;
use SnackQuest\Services\GameService;
use SnackQuest\Services\ProductService;

require dirname(__DIR__) . '/src/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);
$configFile = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--config=')) {
        $configFile = substr($arg, 9);
    }
}

try {
    App::boot($configFile);
} catch (Throwable $e) {
    fwrite(STDERR, 'CONFIG ERROR: ' . $e->getMessage() . "\n");
    exit(2);
}

try {
    $pdo = Database::pdo();
    $prefix = Database::prefix();
    $dialectDir = Database::driver() === 'sqlite'
        ? dirname(__DIR__) . '/migrations/sqlite'
        : dirname(__DIR__) . '/migrations';

    $migTable = $prefix . 'migrations';
    $pdo->exec(Database::driver() === 'sqlite'
        ? "CREATE TABLE IF NOT EXISTS {$migTable} (filename TEXT PRIMARY KEY, applied_at TEXT NOT NULL)"
        : "CREATE TABLE IF NOT EXISTS {$migTable} (filename VARCHAR(120) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $applied = $pdo->query("SELECT filename FROM {$migTable}")->fetchAll(PDO::FETCH_COLUMN);
    $files = glob($dialectDir . '/*.sql') ?: [];
    sort($files);

    $ran = 0;
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $applied, true)) {
            continue;
        }
        $sql = str_replace('{{prefix}}', $prefix, (string)file_get_contents($file));
        // strip SQL comment lines so a leading comment block can never swallow the first statement
        $sql = (string)preg_replace('/^\s*--.*$/m', '', $sql);
        if ($dryRun) {
            echo "DRY-RUN: would apply {$name} (" . strlen($sql) . " bytes)\n";
            continue;
        }
        // Split on statement boundaries; MariaDB DDL is auto-committing, so we apply
        // statement by statement and report exactly where a failure happens.
        $statements = array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql)));
        foreach ($statements as $stmt) {
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            $pdo->exec($stmt);
        }
        if ($name === '003_battle_idempotency.sql') {
            $battleCount = (new GameService(new ProductService()))->rebuildRankingsFromCanonicalBattles();
            echo "REBUILT: {$battleCount} canonical battle ranking(s)\n";
        }
        $ins = $pdo->prepare("INSERT INTO {$migTable} (filename, applied_at) VALUES (:f, :t)");
        $ins->execute(['f' => $name, 't' => gmdate('Y-m-d H:i:s')]);
        App::$log->info('Migration applied: ' . $name);
        echo "APPLIED: {$name}\n";
        $ran++;
    }

    // Seed controlled SnackQuest taste/context tags (idempotent upsert)
    if (!$dryRun) {
        $tags = [
            'zu-suess' => ['Zu süß', 'taste'], 'genau-richtig' => ['Genau richtig', 'taste'],
            'zu-salzig' => ['Zu salzig', 'taste'], 'schoen-scharf' => ['Schön scharf', 'taste'],
            'zu-scharf' => ['Zu scharf', 'taste'], 'knusprig' => ['Knusprig', 'texture'],
            'cremig' => ['Cremig', 'texture'], 'kuenstlich' => ['Künstlich', 'taste'],
            'ueberraschend-gut' => ['Überraschend gut', 'taste'], 'filmabend' => ['Filmabend', 'context'],
            'gaming' => ['Gaming', 'context'], 'unterwegs' => ['Unterwegs', 'context'],
            'teilenwuerdig' => ['Teilenswert', 'context'], 'preis-leistung-gut' => ['Preis-Leistung gut', 'value'],
            'einmal-reicht' => ['Einmal reicht', 'value'],
        ];
        $tbl = Database::table('taste_tags');
        $sqlUp = Database::driver() === 'sqlite'
            ? "INSERT INTO {$tbl} (slug, label, category) VALUES (:s, :l, :c) ON CONFLICT(slug) DO UPDATE SET label = :l, category = :c"
            : "INSERT INTO {$tbl} (slug, label, category) VALUES (:s, :l, :c) ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category)";
        $up = $pdo->prepare($sqlUp);
        foreach ($tags as $slug => [$label, $category]) {
            $up->execute(['s' => $slug, 'l' => $label, 'c' => $category]);
        }
    }

    echo $dryRun ? "Dry-run finished.\n" : "Done. {$ran} migration(s) applied.\n";
    exit(0);
} catch (Throwable $e) {
    App::$log->error('Migration failed: ' . $e->getMessage());
    fwrite(STDERR, 'MIGRATION ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

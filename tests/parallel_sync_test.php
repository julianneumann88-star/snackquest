<?php
declare(strict_types=1);

use SnackQuest\App;
use SnackQuest\Database;

$root = dirname(__DIR__);
$database = $root . '/storage/parallel-sync-' . bin2hex(random_bytes(6)) . '.sqlite';
$barrier = $root . '/storage/parallel-sync-' . bin2hex(random_bytes(6)) . '.barrier';
putenv('SQ_PARALLEL_DB=' . $database);
putenv('SQ_CONFIG=' . __DIR__ . '/config.parallel.php');
require $root . '/src/bootstrap.php';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL parallel sync: {$message}\n");
    exit(1);
}

/**
 * @param list<array{scenario:string,sync_id:string}> $specs
 * @return list<array<string,mixed>>
 */
function runWorkers(string $database, string $barrier, array $specs): array
{
    @unlink($barrier);
    $workers = [];
    foreach ($specs as $spec) {
        $command = [PHP_BINARY];
        if (PHP_OS_FAMILY === 'Windows') {
            $extensionDir = (string)ini_get('extension_dir');
            array_push(
                $command,
                '-d', 'extension_dir=' . $extensionDir,
                '-d', 'extension=php_mbstring.dll',
                '-d', 'extension=php_pdo_sqlite.dll',
                '-d', 'extension=php_sqlite3.dll'
            );
        }
        array_push(
            $command,
            __DIR__ . '/parallel_sync_worker.php',
            $database,
            $barrier,
            $spec['scenario'],
            $spec['sync_id']
        );
        $pipes = [];
        $process = proc_open($command, [1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__));
        if (!is_resource($process)) {
            fail('worker could not start');
        }
        $workers[] = ['process'=>$process,'stdout'=>$pipes[1],'stderr'=>$pipes[2]];
    }
    touch($barrier);

    $results = [];
    foreach ($workers as $worker) {
        $stdout = stream_get_contents($worker['stdout']);
        $stderr = stream_get_contents($worker['stderr']);
        fclose($worker['stdout']);
        fclose($worker['stderr']);
        $exit = proc_close($worker['process']);
        if ($exit !== 0) {
            fail('worker failed: ' . trim((string)$stderr));
        }
        $decoded = json_decode((string)$stdout, true);
        if (!is_array($decoded)) {
            fail('worker returned invalid JSON');
        }
        $results[] = $decoded;
    }
    @unlink($barrier);
    return $results;
}

try {
    App::boot();
    Database::pdo()->exec('PRAGMA journal_mode = WAL');
    foreach (glob($root . '/migrations/sqlite/*.sql') ?: [] as $migration) {
        $sql = str_replace('{{prefix}}', 'sq_', (string)file_get_contents($migration));
        $sql = (string)preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $statement) {
            Database::pdo()->exec($statement);
        }
    }
    $now = gmdate('Y-m-d H:i:s');
    Database::pdo()->prepare(
        'INSERT INTO sq_users(email,email_verified_at,password_hash,display_name,created_at,updated_at) '
        . 'VALUES(:email,:verified,:password,:name,:created,:updated)'
    )->execute([
        'email'=>'parallel@example.test',
        'verified'=>$now,
        'password'=>password_hash('Password123', PASSWORD_DEFAULT),
        'name'=>'Parallel',
        'created'=>$now,
        'updated'=>$now,
    ]);

    $same = runWorkers($database, $barrier, [
        ['scenario'=>'same','sync_id'=>'123e4567-e89b-42d3-a456-426614174100'],
        ['scenario'=>'same','sync_id'=>'123e4567-e89b-42d3-a456-426614174100'],
    ]);
    $sameStatuses = array_count_values(array_map(
        static fn(array $result): string => $result['status'] . ':' . (!empty($result['duplicate']) ? 'duplicate' : 'new'),
        $same
    ));
    if (($sameStatuses['ok:new'] ?? 0) !== 1 || ($sameStatuses['ok:duplicate'] ?? 0) !== 1) {
        fail('same UUID was not resolved as one write plus one duplicate');
    }

    $different = runWorkers($database, $barrier, [
        ['scenario'=>'different','sync_id'=>'123e4567-e89b-42d3-a456-426614174101'],
        ['scenario'=>'different','sync_id'=>'123e4567-e89b-42d3-a456-426614174102'],
    ]);
    $differentStatuses = array_count_values(array_column($different, 'status'));
    if (($differentStatuses['ok'] ?? 0) !== 1 || ($differentStatuses['conflict'] ?? 0) !== 1) {
        fail('competing revisions were not serialized into one write plus one conflict');
    }

    $receiptCount = (int)Database::pdo()->query('SELECT COUNT(*) FROM sq_sync_receipts')->fetchColumn();
    $priceCount = (int)Database::pdo()->query('SELECT COUNT(*) FROM sq_price_entries')->fetchColumn();
    $entryCount = (int)Database::pdo()->query('SELECT COUNT(*) FROM sq_user_product_entries')->fetchColumn();
    if ($receiptCount !== 2 || $priceCount !== 2 || $entryCount !== 2) {
        fail("unexpected persisted counts: receipts={$receiptCount}, prices={$priceCount}, entries={$entryCount}");
    }

    $mixed = runWorkers($database, $barrier, [
        ['scenario'=>'mixed-sync','sync_id'=>'123e4567-e89b-42d3-a456-426614174103'],
        ['scenario'=>'mixed-online','sync_id'=>'123e4567-e89b-42d3-a456-426614174104'],
    ]);
    $mixedStatuses = array_column($mixed, 'status');
    sort($mixedStatuses);
    if (
        !in_array('online', $mixedStatuses, true)
        || (!in_array('ok', $mixedStatuses, true) && !in_array('conflict', $mixedStatuses, true))
    ) {
        fail('online/offline race returned an unexpected outcome');
    }
    $mixedEntry = Database::pdo()->query(
        "SELECT overall_rating,note FROM sq_user_product_entries WHERE user_id=1 AND product_key='off:parallel-mixed'"
    )->fetch();
    $mixedLockCount = (int)Database::pdo()->query(
        "SELECT COUNT(*) FROM sq_sync_locks WHERE user_id=1 AND product_key='off:parallel-mixed'"
    )->fetchColumn();
    if (
        !is_array($mixedEntry)
        || (int)$mixedEntry['overall_rating'] !== 9
        || (string)$mixedEntry['note'] !== 'Online gewinnt versionsgeordnet.'
        || $mixedLockCount !== 1
    ) {
        fail('normal online save was overwritten by an older offline revision');
    }
    echo "PASS parallel online/offline serialization, retry idempotency and price atomicity\n";
} finally {
    @unlink($barrier);
    @unlink($database);
    @unlink($database . '-wal');
    @unlink($database . '-shm');
}

<?php
declare(strict_types=1);

use SnackQuest\App;
use SnackQuest\Services\ProductService;
use SnackQuest\Services\ReviewConflictException;

[$script, $database, $barrier, $scenario, $syncId] = $argv + [null, null, null, null, null, null];
if (!is_string($database) || !is_string($barrier) || !is_string($scenario) || !is_string($syncId)) {
    fwrite(STDERR, "invalid worker arguments\n");
    exit(2);
}

putenv('SQ_PARALLEL_DB=' . $database);
putenv('SQ_CONFIG=' . __DIR__ . '/config.parallel.php');
require dirname(__DIR__) . '/src/bootstrap.php';
App::boot();

$deadline = microtime(true) + 10;
while (!is_file($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}
if (!is_file($barrier)) {
    fwrite(STDERR, "barrier timeout\n");
    exit(3);
}

$product = match ($scenario) {
    'same' => ['key'=>'off:parallel-same','name'=>'Parallel Same','brand'=>'Test','categories'=>'Test','image'=>''],
    'mixed-sync', 'mixed-online' => ['key'=>'off:parallel-mixed','name'=>'Parallel Mixed','brand'=>'Test','categories'=>'Test','image'=>''],
    default => ['key'=>'off:parallel-conflict','name'=>'Parallel Conflict','brand'=>'Test','categories'=>'Test','image'=>''],
};

try {
    if ($scenario === 'mixed-online') {
        $entryId = (new ProductService())->saveReview(
            1,
            $product,
            [
                'overall_rating'=>'9',
                'buy_again'=>'yes',
                'note'=>'Online gewinnt versionsgeordnet.',
            ]
        );
        echo json_encode(['status'=>'online','entry_id'=>$entryId], JSON_UNESCAPED_SLASHES);
        exit(0);
    }
    $result = (new ProductService())->syncReview(
        1,
        $product,
        [
            'overall_rating'=>$scenario === 'mixed-sync' ? '3' : '8',
            'buy_again'=>'yes',
            'note'=>$scenario === 'mixed-sync' ? 'Älterer Offline-Entwurf.' : '',
            'price'=>'2,49',
            'store_name'=>'Parallelmarkt',
            'purchased_at'=>'2026-07-23',
        ],
        $syncId,
        0
    );
    echo json_encode(['status'=>'ok'] + $result, JSON_UNESCAPED_SLASHES);
    exit(0);
} catch (ReviewConflictException $error) {
    echo json_encode(['status'=>'conflict','server_updated_at'=>$error->serverUpdatedAt]);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}

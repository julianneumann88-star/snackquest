<?php
declare(strict_types=1);

$database = (string)getenv('SQ_PARALLEL_DB');
if ($database === '' || !str_ends_with($database, '.sqlite')) {
    throw new RuntimeException('Parallel test database path missing.');
}

return [
    'app_name'=>'SnackQuest',
    'app_version'=>'test',
    'app_env'=>'test',
    'app_base_url'=>'http://127.0.0.1:8792/snackquest',
    'base_path'=>'/snackquest',
    'timezone'=>'Europe/Berlin',
    'db'=>['driver'=>'sqlite','sqlite_path'=>$database,'prefix'=>'sq_'],
    'mail'=>['transport'=>'log'],
    'auth'=>['session_name'=>'sqparallel','google'=>['enabled'=>false]],
    'open_food_facts'=>['base_url'=>'https://world.openfoodfacts.org'],
    'uploads'=>['dir'=>__DIR__.'/../storage/test-uploads','max_bytes'=>8000000],
    'ai'=>['enabled'=>false],
    'log'=>['dir'=>__DIR__.'/../logs','level'=>'error'],
];

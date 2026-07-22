<?php
declare(strict_types=1);
return [
 'app_name'=>'SnackQuest','app_version'=>'test','app_env'=>'test','app_base_url'=>'http://127.0.0.1:8792/snackquest','base_path'=>'/snackquest','timezone'=>'Europe/Berlin',
 'db'=>['driver'=>'sqlite','sqlite_path'=>__DIR__.'/../storage/test.sqlite','prefix'=>'sq_'],
 'mail'=>['transport'=>'log'],'auth'=>['session_name'=>'sqtest','verification_ttl_hours'=>48,'reset_ttl_minutes'=>60,'min_password_length'=>10,'rate_limit_window_s'=>900,'rate_limit_max_attempts'=>50,'google'=>['enabled'=>false,'client_id'=>'','client_secret'=>'','redirect_uri'=>'']],
 'open_food_facts'=>['base_url'=>'https://world.openfoodfacts.org','user_agent'=>'SnackQuest/Test','timeout_seconds'=>2,'cache_ttl_seconds'=>60],
 'uploads'=>['dir'=>__DIR__.'/../storage/test-uploads','max_bytes'=>8000000],'ai'=>['enabled'=>false],'log'=>['dir'=>__DIR__.'/../logs','level'=>'error'],
];

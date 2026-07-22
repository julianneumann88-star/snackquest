<?php
declare(strict_types=1);
return [
  'app_name'=>'SnackQuest','app_version'=>'1.0.0','app_env'=>'production','app_base_url'=>'https://julian-neumann.org/snackquest','base_path'=>'/snackquest','timezone'=>'Europe/Berlin','default_locale'=>'de','default_region'=>'DE',
  'db'=>['driver'=>'mysql','host'=>'','port'=>3306,'name'=>'','user'=>'','pass'=>'','sqlite_path'=>'','prefix'=>'sq_'],
  'mail'=>['transport'=>'smtp','host'=>'smtp.ionos.de','port'=>587,'user'=>'','pass'=>'','from'=>'info@julian-neumann.org','from_name'=>'SnackQuest'],
  'auth'=>['session_name'=>'sqsess','verification_ttl_hours'=>48,'reset_ttl_minutes'=>60,'min_password_length'=>10,'rate_limit_window_s'=>900,'rate_limit_max_attempts'=>8,'google'=>['enabled'=>false,'client_id'=>'','client_secret'=>'','redirect_uri'=>'https://julian-neumann.org/snackquest/auth/callback']],
  'open_food_facts'=>['base_url'=>'https://world.openfoodfacts.org','user_agent'=>'SnackQuest/1.0 (https://julian-neumann.org/snackquest; contact via julian-neumann.org)','timeout_seconds'=>9,'cache_ttl_seconds'=>604800],
  'open_prices'=>['enabled'=>false,'base_url'=>'https://prices.openfoodfacts.org/api/v1'],
  'uploads'=>['dir'=>__DIR__.'/../storage/uploads','max_bytes'=>8000000],
  'ai'=>['enabled'=>false,'base_url'=>'','api_key'=>'','model'=>'local/gpt-oss-20b','timeout_seconds'=>30],
  'admin_user_ids'=>[],'log'=>['dir'=>__DIR__.'/../logs','level'=>'info'],
];

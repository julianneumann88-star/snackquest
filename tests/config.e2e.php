<?php
$cfg=require __DIR__.'/config.test.php';$cfg['db']['sqlite_path']=__DIR__.'/../storage/e2e.sqlite';$cfg['mail']['transport']='log';return $cfg;

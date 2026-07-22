<?php
/** Scheduled retention cleanup. Run daily: php8.3-cli bin/maintenance.php */
declare(strict_types=1);

use SnackQuest\App;
use SnackQuest\Database;

require dirname(__DIR__).'/src/bootstrap.php';
App::boot();

$pdo=Database::pdo();
$now=gmdate('Y-m-d H:i:s');
$cut90=gmdate('Y-m-d H:i:s',time()-90*86400);
$cut2=gmdate('Y-m-d H:i:s',time()-2*86400);
$jobs=[
    [Database::table('auth_tokens'),"DELETE FROM %s WHERE expires_at<:cut",$now],
    [Database::table('rate_limits'),"DELETE FROM %s WHERE window_started_at<:cut",$cut2],
    [Database::table('ai_insights'),"DELETE FROM %s WHERE expires_at IS NOT NULL AND expires_at<:cut",$now],
    [Database::table('audit_events'),"DELETE FROM %s WHERE created_at<:cut",$cut90],
];
$removed=0;
foreach($jobs as[$table,$sql,$cut]){$stmt=$pdo->prepare(sprintf($sql,$table));$stmt->execute(['cut'=>$cut]);$removed+=$stmt->rowCount();}
$logDir=(string)App::$config->get('log.dir');
foreach(glob(rtrim($logDir,'/\\').'/*.log')?:[] as$file){if(is_file($file)&&filemtime($file)<time()-30*86400&&@unlink($file))$removed++;}
echo "Maintenance complete. {$removed} expired record(s)/file(s) removed.\n";

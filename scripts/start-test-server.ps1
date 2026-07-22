$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$db=Join-Path $root 'storage\e2e.sqlite'
if(Test-Path -LiteralPath $db){Remove-Item -LiteralPath $db -Force}
$sessionDir=Join-Path $root 'storage\sessions'
New-Item -ItemType Directory -Force -Path $sessionDir | Out-Null
$php=(Get-Command php).Source
$extDir=Join-Path (Split-Path $php -Parent) 'ext'
$phpArgs=@('-d',"extension_dir=$extDir",'-d','extension=php_mbstring.dll','-d','extension=php_pdo_sqlite.dll','-d','extension=php_sqlite3.dll','-d','extension=php_curl.dll','-d','extension=php_openssl.dll','-d','extension=php_fileinfo.dll','-d','extension=php_gd.dll','-d',"session.save_path=$sessionDir")
& $php @phpArgs (Join-Path $root 'bin\migrate.php') ('--config='+ (Join-Path $root 'tests\config.e2e.php'))
if($LASTEXITCODE -ne 0){exit $LASTEXITCODE}
$env:SQ_CONFIG=(Join-Path $root 'tests\config.e2e.php')
& $php @phpArgs -S 127.0.0.1:8792 (Join-Path $root 'tests\e2e\router.php')

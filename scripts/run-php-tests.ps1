$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$php=(Get-Command php).Source
$extDir=Join-Path (Split-Path $php -Parent) 'ext'
$sessionDir=Join-Path $root 'storage\sessions'
New-Item -ItemType Directory -Force -Path $sessionDir | Out-Null
$phpArgs=@('-d',"extension_dir=$extDir",'-d','extension=php_mbstring.dll','-d','extension=php_pdo_sqlite.dll','-d','extension=php_sqlite3.dll','-d','extension=php_curl.dll','-d','extension=php_openssl.dll','-d','extension=php_fileinfo.dll','-d','extension=php_gd.dll','-d',"session.save_path=$sessionDir")
& $php @phpArgs (Join-Path $root 'tests\run_tests.php')
exit $LASTEXITCODE

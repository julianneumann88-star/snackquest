# Safe IONOS deployment for the isolated /snackquest application.
# Dry-run by default. Production always verifies, backs up, migrates and smokes.
param(
  [switch]$Execute,
  [switch]$SkipVerify,
  [switch]$SkipMigrate,
  [switch]$SkipSmoke
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$projectRoot = Split-Path -Parent $PSScriptRoot
$websiteRoot = Split-Path -Parent $projectRoot
$webroot = '/julian-neumann-org-staging-20260618-174746'
$remoteApp = "$webroot/snackquest"
$remoteAppSsh = $remoteApp.TrimStart('/')
$remoteBackupRoot = "$webroot/_backups"
$remoteStageRoot = "$webroot/_deploy_staging"
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$remoteAppBackup = "$remoteBackupRoot/snackquest-app-$timestamp"
$remoteDbBackup = "$remoteBackupRoot/snackquest-database-$timestamp"
$remoteStage = "$remoteStageRoot/snackquest-$timestamp"
$remotePrevious = "$remoteStageRoot/snackquest-previous-$timestamp"
$remoteFailed = "$remoteStageRoot/snackquest-failed-$timestamp"
$logFile = Join-Path $projectRoot "logs/deploy-$timestamp.log"
$tempDir = Join-Path $env:TEMP "snackquest-deploy-$timestamp"
$ssh = $null
$sftp = $null
$promoted = $false
$hadRemoteApp = $false

New-Item -ItemType Directory -Force -Path (Join-Path $projectRoot 'logs') | Out-Null

function Log([string]$Level, [string]$Message) {
  $line = '{0} | {1,-8} | {2}' -f (Get-Date -Format 'yyyy-MM-ddTHH:mm:ss'), $Level.ToUpper(), $Message
  Write-Host $line
  Add-Content -LiteralPath $logFile -Value $line -Encoding UTF8
}

function PhpQuote([string]$Value) {
  return "'" + $Value.Replace('\', '\\').Replace("'", "\'") + "'"
}

function ShQuote([string]$Value) {
  return "'" + $Value.Replace("'", "'`"`'`"'") + "'"
}

function RemoveDeployTempBestEffort([string]$Path) {
  try {
    $tempRoot = [IO.Path]::GetFullPath($env:TEMP).TrimEnd('\') + '\'
    $resolved = [IO.Path]::GetFullPath($Path)
    $safe = $resolved.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase) `
      -and ([IO.Path]::GetFileName($resolved)).StartsWith('snackquest-deploy-')
    if (-not $safe) {
      throw "Unsafe temporary cleanup target: $resolved"
    }
    if (Test-Path -LiteralPath $resolved) {
      Remove-Item -LiteralPath $resolved -Recurse -Force
    }
  } catch {
    Log warn 'Temporary deployment material could not be fully removed; delete the named SnackQuest temp directory manually.'
  }
}

function Invoke-Verification {
  $commands = @(
    @('npm', @('ci', '--no-audit', '--no-fund')),
    @('npm', @('run', 'build')),
    @('npm', @('run', 'lint')),
    @('npm', @('run', 'audit:secrets')),
    @('npm', @('audit', '--audit-level=high')),
    @('npm', @('test')),
    @('npm', @('run', 'test:e2e'))
  )
  Push-Location $projectRoot
  try {
    foreach ($item in $commands) {
      $name = $item[0]
      $arguments = $item[1]
      Log info "VERIFY: $name $($arguments -join ' ')"
      & $name @arguments
      if ($LASTEXITCODE -ne 0) {
        throw "Verification failed: $name $($arguments -join ' ')"
      }
    }
  } finally {
    Pop-Location
  }
}

function SshCmd([string]$Command) {
  return Invoke-SSHCommand -SessionId $ssh.SessionId -Command $Command -TimeOut 120
}

function EnsureRemoteDir([string]$Path) {
  $current = ''
  foreach ($part in $Path.Trim('/').Split('/')) {
    if ([string]::IsNullOrWhiteSpace($part)) {
      continue
    }
    $current += "/$part"
    if (-not (Test-SFTPPath -SessionId $sftp.SessionId -Path $current)) {
      New-SFTPItem -SessionId $sftp.SessionId -ItemType Directory -Path $current | Out-Null
    }
  }
}

function AssertHttpProtected([string]$RemoteDirectory, [string]$LocalCanary) {
  $webrootPrefix = $webroot.TrimEnd('/') + '/'
  if (-not $RemoteDirectory.StartsWith($webrootPrefix, [StringComparison]::Ordinal)) {
    throw "HTTP protection target is outside the verified webroot: $RemoteDirectory"
  }
  $canaryName = [IO.Path]::GetFileName($LocalCanary)
  $remoteCanary = $RemoteDirectory.TrimEnd('/') + '/' + $canaryName
  try {
    Set-SFTPItem -SessionId $sftp.SessionId -Path $LocalCanary -Destination $RemoteDirectory -Force
    $mode = SshCmd "chmod 600 $(ShQuote $remoteCanary.TrimStart('/')) && test -s $(ShQuote $remoteCanary.TrimStart('/'))"
    if ($mode.ExitStatus -ne 0) {
      throw 'HTTP protection canary could not be created safely.'
    }
    $publicRelative = $remoteCanary.Substring($webroot.Length).TrimStart('/')
    $uri = 'https://julian-neumann.org/' + $publicRelative
    $status = 0
    try {
      $response = Invoke-WebRequest -Uri $uri -UseBasicParsing -TimeoutSec 20
      $status = [int]$response.StatusCode
    } catch {
      try {
        $status = [int]$_.Exception.Response.StatusCode
      } catch {
        $status = 0
      }
    }
    if ($status -notin @(401, 403, 404)) {
      throw "Protected deployment canary unexpectedly returned HTTP $status at /$publicRelative."
    }
  } finally {
    try {
      $canaryCleanup = SshCmd "rm -f -- $(ShQuote $remoteCanary.TrimStart('/'))"
      if ($canaryCleanup.ExitStatus -ne 0) {
        Log warn 'HTTP protection canary cleanup failed; it contains no secret material.'
      }
    } catch {
      Log warn 'HTTP protection canary cleanup failed; it contains no secret material.'
    }
  }
}

Log info "SnackQuest deploy $timestamp | DryRun=$(-not $Execute)"

if ($Execute -and ($SkipVerify -or $SkipMigrate -or $SkipSmoke)) {
  throw 'Production deployment refuses SkipVerify, SkipMigrate and SkipSmoke.'
}
if (-not $SkipVerify) {
  Invoke-Verification
}

try {
  $sftpFile = Join-Path $websiteRoot 'sftp-ssh.txt'
  $dbFile = Join-Path $websiteRoot 'datenbank.txt'
  $mailFile = Join-Path $websiteRoot 'mail-settings.txt'
  $bridgeEnv = Join-Path $websiteRoot 'neumann-ai-api-bridge\worker\.env'
  foreach ($file in @($sftpFile, $dbFile, $mailFile, $bridgeEnv)) {
    if (-not (Test-Path -LiteralPath $file)) {
      throw "Required local secret file missing: $([IO.Path]::GetFileName($file))"
    }
  }

  $sftpLines = @(Get-Content -LiteralPath $sftpFile | Where-Object { $_.Trim() -ne '' })
  if ($sftpLines.Count -lt 4) {
    throw 'SSH credential file has an unexpected format.'
  }
  $sshHost = $sftpLines[0].Trim()
  $sshPort = [int]$sftpLines[1].Trim()
  $sshUser = $sftpLines[2].Trim()
  $sshPass = $sftpLines[3].Trim()

  $dbLines = @(Get-Content -LiteralPath $dbFile)
  $dbHost = ($dbLines | Select-String -Pattern 'hosting-data\.io' | Select-Object -First 1).Line.Trim()
  $dbUser = ($dbLines | Select-String -Pattern '^dbu\d+' | Select-Object -First 1).Line.Trim()
  $passMarker = $dbLines | Where-Object { $_.Trim() -eq 'Passwort' } | Select-Object -First 1
  $dbPassIndex = [array]::IndexOf($dbLines, $passMarker) + 1
  $dbPass = if ($dbPassIndex -gt 0 -and $dbPassIndex -lt $dbLines.Count) { $dbLines[$dbPassIndex].Trim() } else { '' }

  $mailRaw = Get-Content -LiteralPath $mailFile -Raw
  $mailUser = ([regex]::Match($mailRaw, '[\w.+-]+@julian-neumann\.org')).Value
  $mailLines = @(Get-Content -LiteralPath $mailFile | Where-Object { $_.Trim() -ne '' })
  $mailPass = if ($mailLines.Count -gt 0) { $mailLines[-1].Trim() } else { '' }
  $bridgeLine = (Get-Content -LiteralPath $bridgeEnv | Select-String -Pattern '^AI_API_CLIENT_KEY=' | Select-Object -First 1).Line
  $bridgeKey = if ($bridgeLine) { ($bridgeLine -split '=', 2)[1].Trim() } else { '' }
  if (-not $dbHost -or -not $dbUser -or -not $dbPass -or -not $mailUser -or -not $mailPass -or -not $bridgeKey) {
    throw 'A required secret value could not be parsed.'
  }

  Import-Module Posh-SSH -ErrorAction Stop
  $knownHostStore = Get-SSHJsonKnownHost
  $trustedKeys = @($knownHostStore.GetAllKeys() | Where-Object { $_.HostName -eq $sshHost })
  if ($trustedKeys.Count -eq 0) {
    throw 'SSH host is not pinned in the local trusted-host store. Verify the fingerprint out of band before deployment.'
  }
  $credential = New-Object System.Management.Automation.PSCredential(
    $sshUser,
    (ConvertTo-SecureString $sshPass -AsPlainText -Force)
  )
  $ssh = New-SSHSession -ComputerName $sshHost -Port $sshPort -Credential $credential `
    -KnownHost $knownHostStore -ErrorOnUntrusted
  $sftp = New-SFTPSession -ComputerName $sshHost -Port $sshPort -Credential $credential `
    -KnownHost $knownHostStore -ErrorOnUntrusted

  if (-not (Test-SFTPPath -SessionId $sftp.SessionId -Path $webroot)) {
    throw "Expected webroot not found: $webroot"
  }
  $hadRemoteApp = Test-SFTPPath -SessionId $sftp.SessionId -Path $remoteApp
  Log info 'SSH/SFTP connected through the pinned host key; exact webroot verified.'

  $hostQ = ShQuote $dbHost
  $userQ = ShQuote $dbUser
  $passQ = ShQuote $dbPass
  $dbProbe = SshCmd "MYSQL_PWD=$passQ mysql -h $hostQ -u $userQ -N -e 'SHOW DATABASES' 2>/dev/null | grep -v -E '^(information_schema|performance_schema|mysql|sys)$'"
  if ($dbProbe.ExitStatus -ne 0) {
    throw 'MariaDB database inventory failed.'
  }
  $databaseCandidates = @((($dbProbe.Output -join "`n") -split "`r?`n") | Where-Object { $_.Trim() -ne '' } | ForEach-Object { $_.Trim() })
  if ($databaseCandidates.Count -ne 1) {
    throw 'Expected exactly one non-system MariaDB database; refusing ambiguous deployment.'
  }
  $dbName = $databaseCandidates[0]
  if ($dbName -notmatch '^[A-Za-z0-9_]+$') {
    throw 'MariaDB database name failed strict validation.'
  }
  Log info 'MariaDB target discovered and validated.'
  $dbNameQ = ShQuote $dbName

  $phpProbe = SshCmd 'for b in php8.3-cli php8.2-cli php8.1-cli php; do if command -v $b >/dev/null 2>&1; then echo $b; break; fi; done'
  $phpCli = if ($phpProbe.Output) { ($phpProbe.Output | Select-Object -First 1).Trim() } else { '' }
  if ($phpCli -notmatch '^php(?:8\.[123]-cli)?$') {
    throw 'No supported PHP CLI found on IONOS.'
  }

  $googleEnabled = 'false'
  $googleId = ''
  $googleSecret = ''
  $googleFile = Join-Path $projectRoot 'config/google-oauth.json'
  if (Test-Path -LiteralPath $googleFile) {
    $google = Get-Content -LiteralPath $googleFile -Raw | ConvertFrom-Json
    if ($google.web.client_id -and $google.web.client_secret) {
      $googleEnabled = 'true'
      $googleId = $google.web.client_id
      $googleSecret = $google.web.client_secret
    }
  }
  if ($Execute -and $googleEnabled -ne 'true') {
    throw 'Execute blocked: dedicated SnackQuest Google OAuth JSON is missing.'
  }

  New-Item -ItemType Directory -Force -Path $tempDir | Out-Null
  $tempConfig = Join-Path $tempDir 'config.local.php'
  $httpCanary = Join-Path $tempDir ("snackquest-http-canary-" + [guid]::NewGuid().ToString('N') + '.txt')
  [IO.File]::WriteAllText($httpCanary, [guid]::NewGuid().ToString('N'), (New-Object Text.UTF8Encoding($false)))
  $config = @"
<?php
/** Generated SnackQuest production config. Never commit. */
declare(strict_types=1);
return [
 'app_name'=>'SnackQuest','app_version'=>'1.1.0','app_env'=>'production','app_base_url'=>'https://julian-neumann.org/snackquest','base_path'=>'/snackquest','timezone'=>'Europe/Berlin','default_locale'=>'de','default_region'=>'DE',
 'db'=>['driver'=>'mysql','host'=>$(PhpQuote $dbHost),'port'=>3306,'name'=>$(PhpQuote $dbName),'user'=>$(PhpQuote $dbUser),'pass'=>$(PhpQuote $dbPass),'sqlite_path'=>'','prefix'=>'sq_'],
 'mail'=>['transport'=>'smtp','host'=>'smtp.ionos.de','port'=>587,'user'=>$(PhpQuote $mailUser),'pass'=>$(PhpQuote $mailPass),'from'=>$(PhpQuote $mailUser),'from_name'=>'SnackQuest'],
 'auth'=>['session_name'=>'sqsess','verification_ttl_hours'=>48,'reset_ttl_minutes'=>60,'min_password_length'=>10,'rate_limit_window_s'=>900,'rate_limit_max_attempts'=>8,'google'=>['enabled'=>$googleEnabled,'client_id'=>$(PhpQuote $googleId),'client_secret'=>$(PhpQuote $googleSecret),'redirect_uri'=>'https://julian-neumann.org/snackquest/auth/callback']],
 'open_food_facts'=>['base_url'=>'https://world.openfoodfacts.org','user_agent'=>'SnackQuest/1.1 (https://julian-neumann.org/snackquest; contact via julian-neumann.org)','timeout_seconds'=>9,'cache_ttl_seconds'=>604800],
 'open_prices'=>['enabled'=>false,'base_url'=>'https://prices.openfoodfacts.org/api/v1'],
 'uploads'=>['dir'=>__DIR__.'/../storage/uploads','max_bytes'=>8000000,'max_pixels'=>20000000],
 'ai'=>['enabled'=>true,'base_url'=>'https://julian-neumann.org/api/openai.php/v1','api_key'=>$(PhpQuote $bridgeKey),'model'=>'local/runtime','timeout_seconds'=>45,'allowed_hosts'=>['julian-neumann.org']],
 'admin_user_ids'=>[],'log'=>['dir'=>__DIR__.'/../logs','level'=>'info'],
];
"@
  [IO.File]::WriteAllText($tempConfig, $config, (New-Object Text.UTF8Encoding($false)))

  $protectionDir = Join-Path $tempDir 'http-protection'
  New-Item -ItemType Directory -Force -Path $protectionDir | Out-Null
  $denyFile = Join-Path $protectionDir '.htaccess'
  $denyRules = @"
Options -Indexes
<IfModule mod_authz_core.c>
  Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
  Deny from all
</IfModule>
"@
  [IO.File]::WriteAllText($denyFile, $denyRules, (New-Object Text.UTF8Encoding($false)))

  $include = @('.htaccess', 'public', 'src', 'migrations', 'bin')
  $files = @()
  foreach ($item in $include) {
    $full = Join-Path $projectRoot $item
    if (Test-Path $full -PathType Leaf) {
      $files += @{ Local=$full; Relative=$item }
    } else {
      Get-ChildItem -LiteralPath $full -Recurse -File | ForEach-Object {
        $relative = $_.FullName.Substring($projectRoot.Length + 1) -replace '\\', '/'
        $files += @{ Local=$_.FullName; Relative=$relative }
      }
    }
  }
  $files += @{ Local=$tempConfig; Relative='config/config.local.php' }
  Log info "Allowlisted upload candidates: $($files.Count)"

  if (-not $Execute) {
    $files | Select-Object -First 15 | ForEach-Object { Log info "DRY-RUN -> $remoteStage/$($_.Relative)" }
    Log info 'Dry-run complete. No remote files or database rows changed.'
    exit 0
  }

  EnsureRemoteDir $remoteBackupRoot
  EnsureRemoteDir $remoteStageRoot
  Set-SFTPItem -SessionId $sftp.SessionId -Path $denyFile -Destination $remoteBackupRoot -Force
  Set-SFTPItem -SessionId $sftp.SessionId -Path $denyFile -Destination $remoteStageRoot -Force
  $protection = SshCmd "chmod 700 $(ShQuote $remoteBackupRoot.TrimStart('/')) $(ShQuote $remoteStageRoot.TrimStart('/')) && chmod 600 $(ShQuote ($remoteBackupRoot.TrimStart('/') + '/.htaccess')) $(ShQuote ($remoteStageRoot.TrimStart('/') + '/.htaccess')) && test -s $(ShQuote ($remoteBackupRoot.TrimStart('/') + '/.htaccess')) && test -s $(ShQuote ($remoteStageRoot.TrimStart('/') + '/.htaccess'))"
  if ($protection.ExitStatus -ne 0) {
    throw 'HTTP protection for deployment storage could not be verified.'
  }
  AssertHttpProtected $remoteBackupRoot $httpCanary
  AssertHttpProtected $remoteStageRoot $httpCanary

  EnsureRemoteDir $remoteAppBackup
  if ($hadRemoteApp) {
    $copy = SshCmd "umask 077; cp -a $(ShQuote ($remoteAppSsh + '/.')) $(ShQuote ($remoteAppBackup.TrimStart('/') + '/')) && chmod -R u=rwX,go= $(ShQuote $remoteAppBackup.TrimStart('/'))"
    if ($copy.ExitStatus -ne 0) {
      throw 'Remote SnackQuest app backup failed.'
    }
    $backupCheck = SshCmd "test -s $(ShQuote ($remoteAppBackup.TrimStart('/') + '/public/index.php'))"
    if ($backupCheck.ExitStatus -ne 0) {
      throw 'Remote SnackQuest app backup is incomplete.'
    }
  }

  $tablesProbe = SshCmd "MYSQL_PWD=$passQ mysql -h $hostQ -u $userQ $dbNameQ -N -e `"SHOW TABLES LIKE 'sq\\_%'`" 2>/dev/null"
  if ($tablesProbe.ExitStatus -ne 0) {
    throw 'SnackQuest table inventory failed; deployment stopped before upload.'
  }
  $tables = @((($tablesProbe.Output -join "`n") -split "`r?`n") | Where-Object { $_ -ne '' })
  foreach ($table in $tables) {
    if ($table -notmatch '^sq_[a-z0-9_]+$') {
      throw 'Unsafe SnackQuest table name returned by inventory; deployment stopped before upload.'
    }
  }
  if ($hadRemoteApp -and $tables.Count -eq 0) {
    throw 'Existing SnackQuest app has no sq_ tables; deployment stopped before upload.'
  }
  EnsureRemoteDir $remoteDbBackup
  if ($tables.Count -gt 0) {
    $tableArgs = $tables -join ' '
    $dbBackupPath = $remoteDbBackup.TrimStart('/') + '/sq-database.sql'
    $dump = SshCmd "umask 077; chmod 700 $(ShQuote $remoteDbBackup.TrimStart('/')) && MYSQL_PWD=$passQ mysqldump --single-transaction --skip-lock-tables -h $hostQ -u $userQ $dbNameQ $tableArgs > $(ShQuote $dbBackupPath) && chmod 600 $(ShQuote $dbBackupPath) && test -s $(ShQuote $dbBackupPath)"
    if ($dump.ExitStatus -ne 0) {
      throw 'SnackQuest database backup failed or produced an empty file.'
    }
    Log info "Database backup created and verified for $($tables.Count) sq_ tables in the isolated database backup."
  } else {
    $marker = SshCmd "umask 077; chmod 700 $(ShQuote $remoteDbBackup.TrimStart('/')) && printf '%s\n' 'First deployment: no sq_ tables existed.' > $(ShQuote ($remoteDbBackup.TrimStart('/') + '/NO_TABLES.txt')) && chmod 600 $(ShQuote ($remoteDbBackup.TrimStart('/') + '/NO_TABLES.txt'))"
    if ($marker.ExitStatus -ne 0) {
      throw 'First-deployment database backup marker could not be created.'
    }
  }
  AssertHttpProtected $remoteAppBackup $httpCanary
  AssertHttpProtected $remoteDbBackup $httpCanary
  Log info 'Separate app/database backups are HTTP-protected and verified.'

  EnsureRemoteDir $remoteStage
  $stageMode = SshCmd "chmod 700 $(ShQuote $remoteStage.TrimStart('/'))"
  if ($stageMode.ExitStatus -ne 0) {
    throw 'Staging directory permissions could not be restricted.'
  }
  $uploaded = 0
  foreach ($file in $files) {
    $remoteFile = "$remoteStage/$($file.Relative)"
    $remoteDir = $remoteFile -replace '/[^/]+$', ''
    EnsureRemoteDir $remoteDir
    Set-SFTPItem -SessionId $sftp.SessionId -Path $file.Local -Destination $remoteDir -Force
    $uploaded++
    if ($uploaded % 30 -eq 0) {
      Log info "Staging upload $uploaded/$($files.Count)"
    }
  }
  $stageSsh = $remoteStage.TrimStart('/')
  $stageCount = SshCmd "test -s $(ShQuote ($stageSsh + '/public/index.php')) && find $(ShQuote $stageSsh) -type f | wc -l"
  if ($stageCount.ExitStatus -ne 0 -or -not $stageCount.Output -or [int](($stageCount.Output | Select-Object -Last 1).Trim()) -lt $files.Count) {
    throw 'Staged upload verification failed; live app remains unchanged.'
  }

  if ($hadRemoteApp) {
    $storageSource = ShQuote ($remoteAppSsh + '/storage')
    $storageDestination = ShQuote ($stageSsh + '/storage')
    $preserveCommand = "set -e; mkdir -p $(ShQuote ($stageSsh + '/storage/uploads')) $(ShQuote ($stageSsh + '/logs')); if test -d $storageSource; then cp -a $(ShQuote ($remoteAppSsh + '/storage/.')) $(ShQuote ($stageSsh + '/storage/')); src_count=`$(find $storageSource -type f | wc -l); dst_count=`$(find $storageDestination -type f | wc -l); test `"`$src_count`" = `"`$dst_count`"; fi"
    $preserve = SshCmd $preserveCommand
    if ($preserve.ExitStatus -ne 0) {
      throw 'Runtime storage/uploads could not be copied and verified; live app remains unchanged.'
    }
    $logsCopy = SshCmd "cp -a $(ShQuote ($remoteAppSsh + '/logs/.')) $(ShQuote ($stageSsh + '/logs/')) 2>/dev/null"
    if ($logsCopy.ExitStatus -ne 0) {
      Log warn 'Historical logs could not be copied; user uploads were preserved and verified.'
    }
  } else {
    $runtimeDirs = SshCmd "mkdir -p $(ShQuote ($stageSsh + '/storage/uploads')) $(ShQuote ($stageSsh + '/logs'))"
    if ($runtimeDirs.ExitStatus -ne 0) {
      throw 'Runtime directories could not be prepared in staging.'
    }
  }

  $liveModes = SshCmd "chmod 755 $(ShQuote $stageSsh) $(ShQuote ($stageSsh + '/public')) $(ShQuote ($stageSsh + '/src')) $(ShQuote ($stageSsh + '/migrations')) $(ShQuote ($stageSsh + '/bin')) && find $(ShQuote ($stageSsh + '/public')) $(ShQuote ($stageSsh + '/src')) $(ShQuote ($stageSsh + '/migrations')) $(ShQuote ($stageSsh + '/bin')) -type d -exec chmod 755 {} + && find $(ShQuote ($stageSsh + '/public')) $(ShQuote ($stageSsh + '/src')) $(ShQuote ($stageSsh + '/migrations')) $(ShQuote ($stageSsh + '/bin')) -type f -exec chmod 644 {} + && chmod 750 $(ShQuote ($stageSsh + '/config')) && chmod 600 $(ShQuote ($stageSsh + '/config/config.local.php')) && chmod 644 $(ShQuote ($stageSsh + '/.htaccess')) && chmod -R u=rwX,g=rwX,o= $(ShQuote ($stageSsh + '/storage')) $(ShQuote ($stageSsh + '/logs')) && find $(ShQuote ($stageSsh + '/storage')) $(ShQuote ($stageSsh + '/logs')) -type d -exec chmod 770 {} + && find $(ShQuote ($stageSsh + '/storage')) $(ShQuote ($stageSsh + '/logs')) -type f -exec chmod 660 {} +"
  if ($liveModes.ExitStatus -ne 0) {
    throw 'Live-safe staged file permissions could not be applied; live app remains unchanged.'
  }
  AssertHttpProtected $remoteStage $httpCanary
  Log info 'Staged production config is inaccessible through its exact public canary path.'

  # Apply additive migrations with the staged release before promotion. The
  # currently live app remains available and compatible, while the new release
  # can never receive traffic before its required lock/receipt tables exist.
  if (-not $SkipMigrate) {
    $migration = SshCmd "cd $(ShQuote $stageSsh) && $phpCli bin/migrate.php 2>&1"
    $migration.Output | ForEach-Object { Log info "MIGRATE: $_" }
    if ($migration.ExitStatus -ne 0 -or ($migration.Output -join ' ') -notmatch 'Done\.') {
      throw 'Staged migration failed; live app remains unchanged.'
    }
  }

  $previousSsh = $remotePrevious.TrimStart('/')
  $promoteCommand = if ($hadRemoteApp) {
    "set -e; test -d $(ShQuote $stageSsh); mv $(ShQuote $remoteAppSsh) $(ShQuote $previousSsh); if mv $(ShQuote $stageSsh) $(ShQuote $remoteAppSsh); then exit 0; else mv $(ShQuote $previousSsh) $(ShQuote $remoteAppSsh); exit 1; fi"
  } else {
    "set -e; test -d $(ShQuote $stageSsh); mv $(ShQuote $stageSsh) $(ShQuote $remoteAppSsh)"
  }
  $promotion = SshCmd $promoteCommand
  if ($promotion.ExitStatus -ne 0) {
    throw 'Atomic SnackQuest promotion failed; previous live directory was restored.'
  }
  $promoted = $true
  Log info 'Staged release promoted to /snackquest.'

  if (-not $SkipSmoke) {
    $base = 'https://julian-neumann.org/snackquest'
    $checks = @(
      '/', '/features', '/login', '/register', '/forgot-password', '/privacy',
      '/imprint', '/credits', '/terms', '/about', '/status', '/api/health',
      '/manifest.webmanifest', '/sw.js', '/robots.txt', '/sitemap.xml',
      '/assets/css/app.css', '/assets/js/app.js', '/assets/js/scanner.js',
      '/assets/js/share.js', '/assets/icons/icon-v2-180.png',
      '/assets/icons/icon-v2-192.png', '/assets/icons/icon-v2-512.png'
    )
    $failed = 0
    foreach ($path in $checks) {
      try {
        $response = Invoke-WebRequest -Uri ($base + $path) -UseBasicParsing -TimeoutSec 25
        $status = [int]$response.StatusCode
      } catch {
        try { $status = [int]$_.Exception.Response.StatusCode } catch { $status = 0 }
      }
      if ($status -eq 200) {
        Log info "SMOKE OK $path"
      } else {
        Log error "SMOKE FAIL $path -> $status"
        $failed++
      }
    }
    if ($failed -gt 0) {
      throw "$failed public smoke checks failed."
    }
  }

  if ($hadRemoteApp) {
    $cleanupPrevious = SshCmd "rm -rf -- $(ShQuote $previousSsh)"
    if ($cleanupPrevious.ExitStatus -ne 0) {
      Log warn 'Verified previous live directory could not be removed; protected app backup remains available.'
    }
  }
  $promoted = $false
  Log info 'DEPLOYMENT SUCCEEDED: https://julian-neumann.org/snackquest'
  exit 0
} catch {
  if ($promoted) {
    try {
      $failedSsh = $remoteFailed.TrimStart('/')
      if ($hadRemoteApp) {
        $rollback = SshCmd "set -e; test -d $(ShQuote ($remotePrevious.TrimStart('/'))); mv $(ShQuote $remoteAppSsh) $(ShQuote $failedSsh); mv $(ShQuote ($remotePrevious.TrimStart('/'))) $(ShQuote $remoteAppSsh)"
      } else {
        $rollback = SshCmd "set -e; mv $(ShQuote $remoteAppSsh) $(ShQuote $failedSsh)"
      }
      if ($rollback.ExitStatus -eq 0) {
        Log error 'Deployment failed after promotion; the previous SnackQuest app directory was automatically restored. Database backup remains separate and untouched.'
        $failedCleanup = SshCmd "rm -rf -- $(ShQuote $failedSsh)"
        if ($failedCleanup.ExitStatus -ne 0) {
          Log warn 'Failed staged release remains inside the permission-restricted deployment staging directory.'
        }
      } else {
        Log error 'CRITICAL: automatic app-directory restore failed. Use the verified protected app backup; do not use the database SQL as an app rollback path.'
      }
    } catch {
      Log error 'CRITICAL: automatic app-directory restore could not be executed.'
    }
  } elseif ($null -ne $ssh) {
    try {
      $stageCleanup = SshCmd "rm -rf -- $(ShQuote $remoteStage.TrimStart('/'))"
      if ($stageCleanup.ExitStatus -ne 0) {
        Log warn 'Unpromoted staging directory could not be removed; it remains permission-restricted.'
      }
    } catch {
      Log warn 'Unpromoted staging cleanup could not be executed.'
    }
  }
  Log error $_.Exception.Message
  throw
} finally {
  RemoveDeployTempBestEffort $tempDir
  if ($null -ne $sftp) {
    try { Remove-SFTPSession -SessionId $sftp.SessionId | Out-Null } catch { }
  }
  if ($null -ne $ssh) {
    try { Remove-SSHSession -SessionId $ssh.SessionId | Out-Null } catch { }
  }
}

import { readFile, stat } from 'node:fs/promises';
import sharp from 'sharp';

const manifest = JSON.parse(await readFile('public/manifest.webmanifest', 'utf8'));
if (manifest.start_url !== '/snackquest/app' || manifest.scope !== '/snackquest/') {
  throw new Error('manifest subpath mismatch');
}
if (
  !manifest.icons.some((icon) => icon.src.endsWith('icon-maskable-v2-512.png') && icon.purpose === 'maskable')
  || !manifest.icons.some((icon) => icon.src.endsWith('icon-monochrome-v2-512.png') && icon.purpose === 'monochrome')
) {
  throw new Error('brand icon purposes missing');
}
if (!(await stat('public/assets/js/scanner.js')).size) {
  throw new Error('scanner bundle missing');
}
for (const [file, width, height] of [
  ['public/assets/icons/icon-v2-180.png', 180, 180],
  ['public/assets/icons/icon-v2-192.png', 192, 192],
  ['public/assets/icons/icon-v2-512.png', 512, 512],
  ['public/assets/images/og-snackquest.png', 1200, 630],
  ['public/assets/images/github-social-preview.png', 1280, 640],
]) {
  const meta = await sharp(file).metadata();
  if (meta.width !== width || meta.height !== height) {
    throw new Error(`invalid brand asset dimensions: ${file}`);
  }
}

const sw = await readFile('public/sw.js', 'utf8');
for (const required of [
  "VERSION='sq-v1.1.0'",
  "CACHE_PREFIX='sq-'",
  "SCOPE='/snackquest'",
  "OFFLINE_SHELL=SCOPE+'/offline.html'",
  'icon-v2-192.png',
  'register|verify|forgot-password',
  'reset-password|media|s',
  "['token','code','state']",
  "policy.includes('no-store')",
  "policy.includes('private')",
]) {
  if (!sw.includes(required)) throw new Error(`service worker hardening missing: ${required}`);
}
if (sw.includes("PRECACHE=[SCOPE+'/'") || sw.includes("SCOPE+'/offline',")) {
  throw new Error('service worker must not precache a session-aware dynamic page');
}

const layouts = (await readFile('src/Views/layouts/base.php', 'utf8'))
  + (await readFile('src/Views/layouts/app.php', 'utf8'));
if (!layouts.includes('icon-v2-180.png') || layouts.includes('/assets/icons/icon-180.png')) {
  throw new Error('Apple touch icon is not cache-busted');
}
if (!layouts.includes('aria-current="page"') || !layouts.includes('data-clear-review-before')) {
  throw new Error('active navigation or review draft cleanup hook missing');
}

const app = await readFile('public/assets/js/app.js', 'utf8');
for (const required of [
  "navigator.serviceWorker.register('/snackquest/sw.js'",
  "kind: 'working'",
  "kind: 'queue'",
  'const DB_NAME = `snackquest-offline-${userId}`',
  'ownerUserId: userId',
  'row.ownerUserId === userId',
  'data._sync_id',
  'data._base_updated_at',
  "clientDraftAt.name = '_client_draft_at'",
  'rememberReviewClear(clearReviewKey, clearReviewBefore)',
  'Math.max(serverUpdated, clearedBefore)',
  'removeDraftIfWriteToken',
  'saveGeneration += 1',
  'resetScanner',
  "const currentCsrf = qs('input[name=\"_csrf\"]')",
  "params.set('_csrf', currentCsrf)",
  'fetch(REVIEW_SYNC_URL',
  "response.status === 409",
  'setReviewLocked(form, true)',
  'clearDraftsForReview(draft.reviewKey, Number(draft.at || 0))',
  'Offline-Bewertung wurde sicher synchronisiert.',
]) {
  if (!app.includes(required)) throw new Error(`app resilience behavior missing: ${required}`);
}
if (app.includes("addEventListener('load',()=>navigator.serviceWorker.register")) {
  throw new Error('service worker registration is delayed');
}
const logoutFetch = app.indexOf('const response = await fetch(form.action');
const logoutCleanup = app.indexOf('await deleteOfflineDb()', logoutFetch);
if (logoutFetch < 0 || logoutCleanup < logoutFetch) {
  throw new Error('offline data must only be cleared after confirmed server logout');
}

const curlClient = await readFile('src/Support/CurlHttpClient.php', 'utf8');
if (!curlClient.includes('CURLOPT_FOLLOWLOCATION => false') || !curlClient.includes('CURLOPT_MAXREDIRS      => 0')) {
  throw new Error('authenticated HTTP requests must fail closed on redirects');
}

const appCss = await readFile('public/assets/css/app.css', 'utf8');
for (const required of [
  '.install-toast{position:fixed',
  '.app-body>.flash{transform:translateX(-50%)}',
  '.recent-scan-list a{display:grid;gap:.2rem;min-width:0',
  '.recent-scan-list strong{min-width:0',
]) {
  if (!appCss.includes(required)) throw new Error(`mobile QoL hardening missing: ${required}`);
}

const deploy = await readFile('scripts/deploy-snackquest.ps1', 'utf8');
for (const required of [
  "SHOW TABLES LIKE 'sq\\\\_%'",
  'if ($tablesProbe.ExitStatus -ne 0)',
  '$hadRemoteApp = Test-SFTPPath',
  'if ($hadRemoteApp -and $tables.Count -eq 0)',
  'Existing SnackQuest app has no sq_ tables',
  "'^sq_[a-z0-9_]+$'",
  '&& test -s $(ShQuote $dbBackupPath)',
  'database backup failed or produced an empty file',
  'remoteAppBackup',
  'remoteDbBackup',
  'remoteStage',
  'remotePrevious',
  'ErrorOnUntrusted',
  'expected exactly one non-system mariadb database',
  'finally {',
  'RemoveDeployTempBestEffort',
  'runtime storage/uploads could not be copied and verified',
  'chmod -R u=rwX,go=',
  'AssertHttpProtected $remoteBackupRoot $httpCanary',
  'AssertHttpProtected $remoteStage $httpCanary',
  '$remoteCanary.Substring($webroot.Length)',
  'snackquest-http-canary-',
]) {
  if (!deploy.toLowerCase().includes(required.toLowerCase())) {
    throw new Error(`fail-closed deployment backup guard missing: ${required}`);
  }
}
if (/New-(?:SSH|SFTP)Session[^\r\n]*(?:-AcceptKey|-Force)/.test(deploy)) {
  throw new Error('deployment must require the pinned trusted SSH host key');
}
if (deploy.includes("AssertHttpProtected ($remoteAppBackup.TrimStart('/')")) {
  throw new Error('HTTP canaries must use the actual public path without the filesystem webroot prefix');
}
const stagedMigration = deploy.indexOf('Staged migration failed; live app remains unchanged.');
const livePromotion = deploy.indexOf('$promotion = SshCmd $promoteCommand');
if (stagedMigration < 0 || livePromotion < 0 || stagedMigration > livePromotion) {
  throw new Error('required database migrations must finish before the staged release receives live traffic');
}
const deterministicInstall = deploy.indexOf("@('npm', @('ci', '--no-audit', '--no-fund'))");
const verifiedBuild = deploy.indexOf("@('npm', @('run', 'build'))");
if (deterministicInstall < 0 || verifiedBuild < 0 || deterministicInstall > verifiedBuild) {
  throw new Error('deployment verification must install locked dependencies before building');
}

const rootHtaccess = await readFile('.htaccess', 'utf8');
for (const required of [
  '<FilesMatch "(?i)^(?:\\.env(?:\\..*)?|config\\.local\\.php)$">',
  '<IfModule mod_authz_core.c>',
  '<IfModule !mod_authz_core.c>',
]) {
  if (!rootHtaccess.includes(required)) throw new Error(`root secret-file denial missing: ${required}`);
}

const productService = await readFile('src/Services/ProductService.php', 'utf8');
const gameService = await readFile('src/Services/GameService.php', 'utf8');
const migrator = await readFile('bin/migrate.php', 'utf8');
const mysqlSyncMigration = await readFile('migrations/002_sync_receipts.sql', 'utf8');
for (const required of [
  'sync_receipts',
  'sync_locks',
  'acquireSyncLock',
  'if ($ownsTransaction)',
  '$this->acquireSyncLock($userId, $key);',
  'parseDatabaseUtc',
  'ReviewConflictException',
  'UNIQUE KEY uq_sq_sync_receipt (user_id, sync_id)',
]) {
  if (!(productService + mysqlSyncMigration).includes(required)) {
    throw new Error(`persistent sync integrity guard missing: ${required}`);
  }
}
if (
  !gameService.includes('rebuildRankingsFromCanonicalBattles')
  || !gameService.includes('DELETE FROM {$scores}')
  || !migrator.includes("if ($name === '003_battle_idempotency.sql')")
) {
  throw new Error('legacy duplicate battle cleanup must deterministically rebuild rankings');
}

console.log('PASS manifest, assets, private PWA cache guard, active navigation and resilient review drafts');

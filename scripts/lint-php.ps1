$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$failed = @()
Get-ChildItem (Join-Path $root 'src'),(Join-Path $root 'public'),(Join-Path $root 'bin') -Recurse -Filter *.php | ForEach-Object {
    & php -l $_.FullName | Out-Null
    if ($LASTEXITCODE -ne 0) { $failed += $_.FullName }
}
if ($failed.Count -gt 0) { throw "PHP lint failed: $($failed -join ', ')" }
Write-Host 'PHP lint passed.'

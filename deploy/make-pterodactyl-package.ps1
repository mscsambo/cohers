# Builds deploy\dist\cohers-pterodactyl.zip: a self-contained copy for a Pterodactyl container.
# The zip holds one top-level "cohers" folder: unarchive it in /home/container.
# Includes production vendor/ (no Composer needed on the server), start.sh and .env.pterodactyl.
# Excludes secrets (.env), local data (SQLite, photos, logs). Also copies start-all.sh to dist\.
# Run from VS Code terminal in D:\cohers:   .\deploy\make-pterodactyl-package.ps1

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$dist = Join-Path $PSScriptRoot 'dist'
# Build on the project's own drive (not %TEMP% on C:), including Composer's cache.
$base = Join-Path $PSScriptRoot ('.build\cohers-ptero-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
$env:COMPOSER_CACHE_DIR = Join-Path $PSScriptRoot '.build\composer-cache'
$stage = Join-Path $base 'cohers'
$out = Join-Path $dist 'cohers-pterodactyl.zip'
New-Item -ItemType Directory -Force $dist, $stage | Out-Null

Push-Location $root
try {
    php artisan optimize:clear | Out-Null
    # Copy the project into a staging folder (same exclusions as the server package).
    # Windows PowerShell cannot pipe binary data, so go through a temporary .tar file.
    $tmpTar = "$base.tar"
    tar.exe -cf $tmpTar `
        --exclude=./vendor --exclude=./node_modules --exclude=./.env --exclude=./.env.backup `
        --exclude=./database/database.sqlite --exclude=./database/database.sqlite-journal `
        --exclude=./storage/app/private --exclude=./storage/logs --exclude=./storage/framework `
        --exclude=./storage/pail --exclude=./bootstrap/cache/*.php --exclude=./deploy/dist --exclude=./deploy/.build `
        --exclude=./.phpunit.cache --exclude=./.phpunit.result.cache --exclude=./.claude --exclude=./tests `
        .
    tar.exe -xf $tmpTar -C $stage
    Remove-Item $tmpTar -Force
} finally { Pop-Location }

Push-Location $stage
try {
    Write-Host 'Installing production PHP dependencies into the package...'
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
    if ($LASTEXITCODE -ne 0) { throw 'composer install failed' }
    Copy-Item deploy\pterodactyl\start.sh, deploy\pterodactyl\tunnel.sh, deploy\pterodactyl\.env.pterodactyl -Destination . -Force
    New-Item -ItemType Directory -Force '.bin' | Out-Null
    Set-Content -Path '.bin\PUT-CLOUDFLARED-HERE.txt' -Value 'Optional, for the Telegram Mini App: upload the Linux cloudflared program here, named "cloudflared" (https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64).' -NoNewline
    foreach ($d in 'storage\app\private', 'storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs') {
        New-Item -ItemType Directory -Force $d | Out-Null
        Set-Content -Path (Join-Path $d '.gitkeep') -Value '' -NoNewline
    }
    New-Item -ItemType Directory -Force '.php' | Out-Null
    Set-Content -Path '.php\PUT-PHP-BINARY-HERE.txt' -Value 'Only needed if the container has no PHP 8.2+: upload a Linux x86_64 PHP binary here, named "php".' -NoNewline
    if (Test-Path $out) { Remove-Item $out -Force }
    tar.exe -a -cf $out -C $base cohers
} finally { Pop-Location; Remove-Item -Recurse -Force $base }

Copy-Item (Join-Path $PSScriptRoot 'pterodactyl\start-all.sh') (Join-Path $dist 'start-all.sh') -Force
$size = '{0:N1} MB' -f ((Get-Item $out).Length / 1MB)
Write-Host "Package ready: $out ($size)" -ForegroundColor Green
Write-Host "Also upload: $(Join-Path $dist 'start-all.sh') -> /home/container/start-all.sh"

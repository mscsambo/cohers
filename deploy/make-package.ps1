# Builds deploy\dist\cohers.tar.gz from this project for upload to the server.
# Run from VS Code terminal in D:\cohers:   .\deploy\make-package.ps1
# Excludes secrets (.env), local data (SQLite, uploaded photos, logs) and vendor/ (installed on the server).

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$dist = Join-Path $PSScriptRoot 'dist'
New-Item -ItemType Directory -Force $dist | Out-Null
$out = Join-Path $dist 'cohers.tar.gz'

Push-Location $root
try {
    php artisan optimize:clear | Out-Null
    tar.exe -czf $out `
        --exclude=./vendor --exclude=./node_modules --exclude=./.env --exclude=./.env.backup `
        --exclude=./database/database.sqlite --exclude=./database/database.sqlite-journal `
        --exclude=./storage/app/private --exclude=./storage/logs --exclude=./storage/framework `
        --exclude=./storage/pail --exclude=./bootstrap/cache/*.php `
        --exclude=./deploy/dist --exclude=./deploy/.build --exclude=./.phpunit.cache --exclude=./.phpunit.result.cache --exclude=./.claude `
        .
} finally { Pop-Location }

$size = '{0:N1} MB' -f ((Get-Item $out).Length / 1MB)
Write-Host "Package ready: $out ($size)" -ForegroundColor Green
Write-Host "Upload it and deploy.sh to the server's /root folder, then run: sudo bash /root/deploy.sh /root/cohers.tar.gz"

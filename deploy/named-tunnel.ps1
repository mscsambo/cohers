# Fixed-address HTTPS tunnel for local COHERS (Cloudflare named tunnel).
# Unlike the quick tunnel (local-tunnel.ps1), the address never changes.
#
# One-time setup per computer (see docs in DEPLOY-PTERODACTYL.md / chat notes):
#   1. Cloudflare dashboard -> Zero Trust -> Networks -> Tunnels -> Create a tunnel (Cloudflared),
#      name it e.g. cohers-pc1, copy the token.
#   2. In that tunnel add a Public Hostname: cohers-pc1 . anajak.cloud -> HTTP -> 127.0.0.1:8000
#   3. Add to this computer's .env:
#        CLOUDFLARE_TUNNEL_TOKEN=eyJ...
#        CLOUDFLARE_TUNNEL_URL=https://cohers-pc1.anajak.cloud
# Started automatically by start-cohers.bat when CLOUDFLARE_TUNNEL_TOKEN is set in .env.

$root = Split-Path $PSScriptRoot -Parent
Set-Location $root

function Get-EnvValue([string]$name) {
    $line = Get-Content (Join-Path $root '.env') | Where-Object { $_ -match "^$name=" } | Select-Object -First 1
    if ($line) { return ($line -replace "^$name=", '').Trim().Trim('"') }
    return $null
}

$token = Get-EnvValue 'CLOUDFLARE_TUNNEL_TOKEN'
$url = Get-EnvValue 'CLOUDFLARE_TUNNEL_URL'
if (-not $token -or -not $url) {
    Write-Host 'CLOUDFLARE_TUNNEL_TOKEN and CLOUDFLARE_TUNNEL_URL must be set in .env (see the top of this script).' -ForegroundColor Red
    exit 1
}

$exe = (Get-Command cloudflared -ErrorAction SilentlyContinue).Source
if (-not $exe) { $exe = 'C:\Program Files (x86)\cloudflared\cloudflared.exe' }
if (-not (Test-Path $exe)) {
    Write-Host 'cloudflared is not installed. Install it once with:' -ForegroundColor Red
    Write-Host '  winget install --id Cloudflare.cloudflared'
    exit 1
}

Write-Host "Fixed address: $url" -ForegroundColor Green
php artisan cohers:telegram-menu-button $url
Write-Host ''
Write-Host 'Starting the tunnel. Keep this window open; Ctrl+C stops it.'
& $exe tunnel --no-autoupdate run --token $token

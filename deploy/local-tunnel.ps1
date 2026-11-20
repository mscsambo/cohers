# Local testing of the Telegram Mini App on this PC.
# Starts a free Cloudflare quick tunnel to COHERS on http://127.0.0.1:8000 and points the
# bot's menu button at the new https address. Keep the window open while testing; Ctrl+C stops it.
# Started automatically by start-cohers.bat when CLOUDFLARE_TUNNEL_TOKEN is not set.

$root = Split-Path $PSScriptRoot -Parent
Set-Location $root

$exe = (Get-Command cloudflared -ErrorAction SilentlyContinue).Source
if (-not $exe) { $exe = 'C:\Program Files (x86)\cloudflared\cloudflared.exe' }
if (-not (Test-Path $exe)) {
    Write-Host 'cloudflared is not installed. Install it once with:' -ForegroundColor Red
    Write-Host '  & "$env:LOCALAPPDATA\Microsoft\WindowsApps\winget.exe" install --id Cloudflare.cloudflared'
    exit 1
}

$log = Join-Path $root 'storage\logs\tunnel-local.log'
Remove-Item $log -ErrorAction SilentlyContinue
Write-Host 'Starting the HTTPS tunnel to http://127.0.0.1:8000 ...'
$tunnel = Start-Process -FilePath $exe -ArgumentList 'tunnel', '--url', 'http://127.0.0.1:8000', '--no-autoupdate' `
    -RedirectStandardError $log -NoNewWindow -PassThru

$url = $null
for ($i = 0; $i -lt 60 -and -not $url -and -not $tunnel.HasExited; $i++) {
    Start-Sleep -Seconds 1
    if (Test-Path $log) {
        $match = Select-String -Path $log -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' | Select-Object -First 1
        if ($match) { $url = $match.Matches[0].Value }
    }
}
if (-not $url) {
    Write-Host "The tunnel did not start. Details: $log" -ForegroundColor Red
    if (-not $tunnel.HasExited) { Stop-Process -Id $tunnel.Id }
    exit 1
}

Write-Host ''
Write-Host "Mini App address: $url" -ForegroundColor Green
php artisan cohers:telegram-menu-button $url
Write-Host ''
Write-Host 'Open the bot in Telegram and tap the COHERS button. Keep this window open while testing; Ctrl+C stops the tunnel.'
Wait-Process -Id $tunnel.Id

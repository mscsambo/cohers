@echo off
REM COHERS local runner - starts everything in one go:
REM   web dashboard, queue worker (AI triage, routing, alerts), scheduler,
REM   Telegram polling (if a bot token is set) and the HTTPS tunnel for the Mini App.
REM The queue worker and Telegram polling run in a loop: when AI or bot settings are saved
REM in Admin > Configuration they exit and come straight back with the new settings.
REM To stop COHERS, close the COHERS windows.
cd /d "%~dp0"

start "COHERS web"       cmd /k php artisan serve --host=127.0.0.1 --port=8000
start "COHERS queue"     cmd /k "for /l %%i in (0,0,1) do (php artisan queue:work --tries=5 --backoff=10 & echo Queue worker restarting... & timeout /t 3 /nobreak >nul)"
start "COHERS scheduler" cmd /k php artisan schedule:work

findstr /r /c:"^TELEGRAM_BOT_TOKEN=..*" .env >nul
if %errorlevel%==0 (
    start "COHERS telegram" cmd /k "for /l %%i in (0,0,1) do (php artisan cohers:telegram-poll & echo Telegram polling restarting... & timeout /t 10 /nobreak >nul)"
) else (
    echo TELEGRAM_BOT_TOKEN is empty - bot runs in simulated mode, polling not started.
)

timeout /t 3 >nul

REM HTTPS tunnel: fixed address if CLOUDFLARE_TUNNEL_TOKEN is set in .env, otherwise a random trycloudflare address.
findstr /r /c:"^CLOUDFLARE_TUNNEL_TOKEN=..*" .env >nul
if %errorlevel%==0 (
    start "COHERS tunnel" cmd /k powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0deploy\named-tunnel.ps1"
) else (
    start "COHERS tunnel" cmd /k powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0deploy\local-tunnel.ps1"
)

start "" http://127.0.0.1:8000

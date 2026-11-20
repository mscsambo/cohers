# Running COHERS on the Pterodactyl server (next to geminiprobot)

Your server: **my.anajak.cloud** · port **24063** · SFTP `my.anajak.cloud:2022`, user `sambochhorn.70c300f4`

Layout after install:

```
/home/container/
├── start-all.sh          ← starts geminiprobot + COHERS (replaces the old one; demobot no longer started)
├── geminiprobot/         ← unchanged
├── demobot/              ← unchanged, just not started
└── cohers/               ← this app (unzipped from cohers-pterodactyl.zip)
    ├── start.sh
    └── .php/             ← only needed if the container has no PHP (see Step 2)
```

COHERS runs fully inside `cohers/`: PHP libraries are bundled, the database is `cohers/database/database.sqlite`,
and `start.sh` runs the dashboard (port 24063), queue worker, scheduler and Telegram polling.

---

## Step 1 — Upload

From `D:\cohers\deploy\dist\` (rebuild any time with `.\deploy\make-pterodactyl-package.ps1`):

1. Panel → **Files** → in `/home/container` → **Upload** `cohers-pterodactyl.zip` → right-click → **Unarchive**.
   You now have `/home/container/cohers/`. Delete the zip.
2. Upload **`start-all.sh`** to `/home/container/`, replacing the old one. Before replacing, you can
   download the old file as a backup.

## Step 2 — PHP (your image is Python-based)

COHERS needs **PHP 8.2+**. `start.sh` first uses the container's `php` if it has one. If not, it uses a
PHP program you place at **`/home/container/cohers/.php/php`**. It never downloads anything by itself.

Two ways to provide PHP — choose one:

- **A. Static PHP binary (no admin needed).** Download a *Linux x86_64 CLI* build of PHP 8.3 from the
  static-php-cli project's download page (dl.static-php.dev → `static-php-cli/common/`, file
  `php-8.3.xx-cli-linux-x86_64.tar.gz`), extract it on your PC (it contains one file named `php`), and upload
  that file to `cohers/.php/php`. Its "common" build includes the extensions COHERS needs.
- **B. Ask the panel admin** for an image that has both Python (for your bots) and PHP 8.2+.

If PHP is missing or lacks an extension, the Console shows exactly what is missing and COHERS stops;
**geminiprobot keeps running either way.**

## Step 3 — Startup command

Panel → **Startup** → Startup command must be `bash /home/container/start-all.sh` (it already is if you used
the previous `start-all.sh`).

## Step 4 — Bot token

COHERS must use **its own Telegram bot**, different from geminiprobot's token (one bot token can only be read by
one program). Also stop `telegram-poll` on your PC.

Either put it in `cohers/.env.pterodactyl` → `TELEGRAM_BOT_TOKEN=` before the first start, or enter it later in
**Admin → Configuration** (picked up within 30 seconds).

## Step 5 — Start and save the passwords

Panel → **Console** → **Restart**. The first COHERS start takes 1–2 minutes and prints, between the gemini
bot's lines:

```
[cohers …] Demo accounts — SAVE THESE PASSWORDS (shown once):
| admin@cohers.test | System Administrator | xxxxxxxxxxxxxx |
...
[cohers …] Dashboard listening on 0.0.0.0:24063
```

Copy the passwords immediately — they are not shown again.

## Step 6 — Use it

1. Open **http://my.anajak.cloud:24063** → sign in as `admin@cohers.test` → set up two-factor authentication.
2. **Admin → Accounts & Roles** → create your own admin account.
3. **Admin → Configuration** → bot token → **Save** → **Test connection**.
4. **My Telegram** → connect your Telegram for alerts. Send `/start` to the COHERS bot and submit a test report.

## Step 7 — Telegram Mini App over HTTPS (optional, free)

Telegram only opens Mini Apps from an `https://` address. COHERS can get one for free from a Cloudflare
**quick tunnel** (no Cloudflare account): it runs inside this container and connects *out* to Cloudflare, so the
single port is no problem.

1. Download the Linux program **cloudflared** (about 40 MB) on your PC:
   https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64
2. Panel → **Files** → `cohers/.bin/` → upload it and **rename it to `cloudflared`** (no extension).
3. **Restart**. Within a minute the Console prints:
   ```
   [cohers …] Mini App HTTPS address: https://some-random-words.trycloudflare.com
   [cohers …] Bot menu button now opens https://some-random-words.trycloudflare.com/tg
   ```
4. In Telegram, open the COHERS bot → tap the **COHERS** button beside the message box. Reporters see *My reports*
   and *New report*; linked officers and staff are signed in to their own pages.

The address is **random and changes on every restart** (or if Cloudflare drops the tunnel); COHERS then starts a
new tunnel and points the bot's button at the new address by itself, so nothing needs to be done. The dashboard keeps
working at the normal `http://…:24063` address too. Quick tunnels are meant for testing and pilots and have no uptime
promise; for a fixed address use a named Cloudflare tunnel with your own domain. To turn the tunnel off, delete
`cohers/.bin/cloudflared` (or add `export COHERS_TUNNEL=0` as line 2 of `cohers/start.sh`) and restart.
Log: `cohers/storage/logs/tunnel.log`.

## Day-to-day

| Task | How |
|---|---|
| Restart | Console → **Restart** (restarts geminiprobot too; COHERS data is kept) |
| Logs | Files → `cohers/storage/logs/` → `laravel.log`, `worker.log`, `telegram.log`, `scheduler.log` |
| Update COHERS | Rebuild zip → upload to **`/home/container`** (not inside `cohers`) → Unarchive there → delete the zip → Restart. This overwrites code only; `.env`, `database/database.sqlite`, `storage/` and `.php/php` are kept. **Never move folders onto `cohers/`** — the panel's Move *replaces* a folder, which deletes the database. If the database is ever lost, the next start sets it up again and prints new passwords. |
| Clean demo again | Stop → delete `cohers/.env` and `cohers/database/database.sqlite` → Start (new passwords printed) |
| Pilot without demo data | Before the first start, add `export SEED_DEMO=0` as line 2 of `cohers/start.sh` |
| Back up | Download `cohers/database/database.sqlite`, `cohers/storage/app/private/` (photos) **and `cohers/.env`** — the `APP_KEY` in `.env` is needed to read the two-factor secrets and the bot token / Gemini key saved in Admin → Configuration. Always restore the database together with its `.env`. |

## Troubleshooting

**Error 500 after the two-factor code, or "your two-factor code cannot be checked"** — the database was restored
next to a different `.env` (a new `APP_KEY`). The Console shows `APP_KEY in .env is not the key this database was
encrypted with` at start-up.

1. Best: Stop → put back the `APP_KEY=` line from your backed-up `.env` into `cohers/.env` → Start. Everything,
   including the saved bot token and Gemini key, works again.
2. If the old `.env` is lost: add `COHERS_RESET_MFA=admin@example.org` (your sign-in email; several separated by
   commas) to `cohers/.env` → Restart → sign in and set up two-factor again → **remove that line** → re-enter the
   Telegram bot token and Gemini key in Admin → Configuration.

## Limits

- **HTTP on port 24063** — browsers show "Not secure". Telegram uses polling, so the bot works normally.
- **`https://cohers.anajak.cloud`** needs whoever runs the host machine to add an nginx reverse proxy to
  `127.0.0.1:24063` with a Let's Encrypt certificate; then set `APP_URL=https://cohers.anajak.cloud` and
  `SESSION_SECURE_COOKIE=true` in `cohers/.env` and Restart.
- The built-in PHP web server suits a demo or small pilot. For a bigger rollout use a normal Ubuntu server with
  `deploy/deploy.sh`.

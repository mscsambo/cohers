# Deploying COHERS to the server (my.anajak.cloud)

Target: **https://cohers.anajak.cloud** on Ubuntu 22.04 (`208.84.103.38`).
You run every step yourself; nothing here needs to be typed by anyone else.

The installer only adds things for COHERS: its own folder (`/var/www/cohers`), MySQL database, nginx site,
worker and cron entry. It does **not** touch the existing site on `my.anajak.cloud`, other databases, or the
server's default PHP version.

---

## Step 0 — Before you start (5 minutes)

1. **DNS** — in Cloudflare, zone **anajak.cloud**, add:

   | Type | Name | Content | Proxy |
   |---|---|---|---|
   | A | `cohers` | `208.84.103.38` | **DNS only** (grey cloud) |

   Check from your PC (must print `208.84.103.38`):
   ```
   nslookup cohers.anajak.cloud 1.1.1.1
   ```
2. **Ports 80 and 443** must reach this server (they already serve nginx today).
3. **Stop local polling** on your PC (`Ctrl+C` in the `cohers:telegram-poll` window, or close the
   `COHERS telegram` window). The server will receive Telegram through a webhook, and only one can be active.
4. Have ready: your bot token (Telegram @BotFather → your bot → API token) and, optionally, a Gemini API key.
   Both can also be entered later in **Admin → Configuration**.

## Step 1 — Build the package (on your PC, VS Code terminal in `D:\cohers`)

```powershell
.\deploy\make-package.ps1
```

This creates `D:\cohers\deploy\dist\cohers.tar.gz`. It contains the code only — **no** `.env`, passwords,
local database or photos.

## Step 2 — Upload two files to the server's `/root` folder

Use the same port/login you normally use. From the VS Code terminal (replace the port if yours is 24063):

```powershell
scp -P 22 deploy\dist\cohers.tar.gz deploy\deploy.sh root@my.anajak.cloud:/root/
```

Or drag the two files into `/root` with **WinSCP** / **FileZilla** (SFTP).

## Step 3 — Run the installer (on the server)

Log in over SSH, then:

```bash
sudo bash /root/deploy.sh /root/cohers.tar.gz
```

It asks a few questions — press **Enter** to accept the default in brackets:

| Question | Suggested answer |
|---|---|
| Domain | `cohers.anajak.cloud` |
| Email for the certificate | your email (renewal warnings) |
| Your administrator login email | your email — a random password is printed |
| Load demo data | `y` for a demo, `n` for a clean pilot |
| Telegram bot token | paste it, or leave empty |
| Telegram bot username | e.g. `cohers_bot` |
| Gemini API key | paste it, or leave empty for offline triage |

It takes about 5–10 minutes the first time. At the end it prints:

- your **administrator password** and, if demo data was loaded, **new random passwords** for the demo
  accounts — **copy them somewhere safe**, they are not shown again;
- the dashboard address.

Every run is logged to `/var/log/cohers-deploy.log`.

## Step 4 — Check it works

1. Open **https://cohers.anajak.cloud** and sign in with the administrator email + printed password.
2. You will be asked to set up **two-factor authentication** (scan the QR code with Google/Microsoft Authenticator).
3. **Admin → Configuration** → *Test connection* (Telegram) and *Run test call* (Gemini).
4. **My Telegram** (top bar) → connect your own Telegram to receive alerts.
5. Send `/start` to the bot from a phone, submit a test report, and watch it appear in **Reports**.

On the server:
```bash
supervisorctl status cohers-worker      # should say RUNNING
tail -n 50 /var/www/cohers/storage/logs/laravel.log
```

## Updating later

On your PC: `.\deploy\make-package.ps1`, upload the new `cohers.tar.gz` (and `deploy.sh`) again, then on the server:

```bash
sudo bash /root/deploy.sh /root/cohers.tar.gz
```

The same command detects the existing install: it keeps `.env`, the database and uploaded photos, runs new
migrations and restarts the worker.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "Certificate not issued" | DNS not pointing here yet, or Cloudflare proxy is orange. Fix DNS, then `certbot --nginx -d cohers.anajak.cloud --redirect` and re-run `deploy.sh` (it switches to HTTPS settings). |
| "Cannot log in to MySQL as root" | MySQL already has a root password: `MYSQL_ROOT_PASSWORD='...' sudo -E bash /root/deploy.sh /root/cohers.tar.gz` |
| Bot doesn't answer | Admin → Configuration → *Test connection*; check *Webhook* shows `https://cohers.anajak.cloud/api/telegram/webhook`. Make sure nothing is polling on your PC. |
| Alerts not delivered | `supervisorctl status cohers-worker`; Admin → Alerts & Jobs shows errors per message. |
| You changed `.env` by hand | `sudo -u www-data php8.2 /var/www/cohers/artisan optimize` |
| Forgot admin password | `sudo -u www-data php8.2 /var/www/cohers/artisan cohers:create-admin you@example.org` (prints a new one) |
| Local PC: `telegram-poll` refuses to start | Expected: the bot belongs to the server now. Use a separate test bot token locally. |

## Notes

- Data on your PC (SQLite, the test reporter "Dara", photos) is **not** copied; the server starts fresh.
- The demo data contains placeholder Telegram IDs for demo officers/reporters; messages to them show as
  *Undeliverable* in Alerts & Jobs. Link real officers with **Generate activation link**.
- The installer has been syntax-checked but has not been run end-to-end on a fresh Ubuntu machine from here.
  If any step fails, it stops with the line number; send the last lines of `/var/log/cohers-deploy.log`.

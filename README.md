# COHERS — Community Reporting, Verification & Early Warning

Laravel 11 implementation of the **COHERS SRS v1.0** (`docs/COHERS_Full_Workflow_Requirements_and_Development_Structure_v1.0.docx`).
One Laravel app, one database, a Telegram bot for reporters and focal points, a web dashboard for district officers and
administrators, Gemini as an **advisory-only** classifier, and Laravel queues/scheduler for automation. No Make.com.

## Quick start (Windows, local demo)

```bat
cd D:\cohers
composer install                    :: already done
php artisan migrate:fresh --seed    :: reference data + 6 weeks of demo activity
start-cohers.bat                    :: web + queue worker + scheduler + HTTPS tunnel (+ Telegram polling if token set)
```

Open http://127.0.0.1:8000 and sign in with a demo account. The demo accounts and their shared password are
documented at the top of `database/seeders/DemoSeeder.php` (`admin@cohers.test`, `district.baphnum@cohers.test`,
`district.mesang@cohers.test`). The demo seeder turns MFA *off* via a system setting; turn it back on in
**Admin → Language & Settings** (it is mandatory for the pilot).

Run the tests (SRS §14 acceptance scenarios + page smoke tests):

```bat
php artisan test
```

## Connecting Telegram

1. Create a bot with @BotFather and set in `.env`:
   `TELEGRAM_BOT_TOKEN=…`, `TELEGRAM_BOT_USERNAME=…`, `TELEGRAM_WEBHOOK_SECRET=<long random string>`.
2. **Local:** `php artisan cohers:telegram-poll` (long polling; `start-cohers.bat` starts it automatically).
   Use a dedicated bot token — only one process may poll a token at a time.
3. **Production (HTTPS):** `php artisan cohers:telegram-webhook https://your-domain` registers
   `POST /api/telegram/webhook` with the secret header.
4. Officers: Admin → Accounts & Roles → *Generate activation link* (single use, expires in 60 min).

Without a token the bot runs in **simulated mode**: every notification is still queued and logged
(`notification_logs.delivery_status = SIMULATED`) so the full workflow can be demonstrated.

## Gemini

Set `GEMINI_API_KEY` (and optionally `GEMINI_MODEL`) and choose the driver in **Admin → AI & Categories**
(`gemini`, `keyword` offline heuristic, or `none` = always manual triage). Only minimized text is sent; responses are
schema-validated; failures keep the report in `TRIAGE_REVIEW` and retry with backoff.

## Switching to MySQL (spec target)

Laragon ships MySQL 8.4. Create a database `cohers`, then in `.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cohers
DB_USERNAME=root
DB_PASSWORD=
```

and run `php artisan migrate:fresh --seed`. The schema and queries are written to be database-portable
(aggregation is done in PHP), but the suite has so far only been run on SQLite.

## Production checklist

- `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, `COHERS_REQUIRE_MFA=true`.
- Supervisor/NSSM for `php artisan queue:work` and a cron/Task Scheduler entry for `php artisan schedule:run` every minute.
- Back up the database and `storage/app/private` (evidence photos). Nothing is hard-deleted.
- Approve the pilot values (acknowledgement targets, rules, privacy text) — they are configurable in the admin portal.

## Where things live (SRS §12 structure)

| Area | Code |
|---|---|
| Enums (status, roles, priority, sector…) | `app/Enums` |
| Telegram webhook / bot flows | `app/Http/Controllers/Telegram`, `app/Services/Telegram` (`TelegramConversationService`, `TelegramWebhookService`) |
| District portal | `app/Http/Controllers/District`, `resources/views/district` |
| Admin portal | `app/Http/Controllers/Admin`, `resources/views/admin` |
| Authorization | `app/Policies/ReportPolicy.php`, `Report::scopeVisibleTo()`, `app/Http/Middleware` |
| Reporting / duplicates | `app/Services/Reporting` |
| AI triage + rules | `app/Services/AI` (`GeminiTriageService`, `ReportTriageService`, `TriageRuleEngine`) |
| Routing | `app/Services/Routing/ReportRoutingService.php` |
| Verification | `app/Services/Verification/ReportVerificationService.php` |
| Escalation & closure | `app/Services/Escalation/EscalationService.php` |
| Notifications | `app/Services/Notification/NotificationService.php`, `app/Jobs/SendTelegramNotificationJob.php` |
| Analytics | `app/Services/Analytics/ReportAnalyticsService.php` |
| Jobs | `app/Jobs` (`ProcessIncomingReportJob`, `AnalyzeReportWithGeminiJob`, `RouteReportJob`, `CheckOverdueAssignmentsJob`, …) |
| Khmer / English copy | `lang/km/bot.php`, `lang/en/bot.php` (overridable in Admin → Language & Settings) |
| Geography | `database/data/prey_veng_gazetteer.csv` (1,311 Prey Veng units with coordinates), `php artisan cohers:import-geography` |

## Workflow summary

Reporter (Telegram) → durable report + reference code → duplicate scoring → AI advisory triage → deterministic
safety rules → route primary/backup VHSG (human) or CAO (animal/environment) → officer acknowledges / verifies /
requests info / escalates in Telegram → district reviews escalations, reassigns, links incidents, approves closure →
dashboards & exports. CRITICAL alerts the district in parallel with routing. Overdue acknowledgements trigger
reminder → backup → district alert according to priority. Every privileged action is written to the append-only
`audit_logs` table.

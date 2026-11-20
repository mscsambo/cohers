<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DeliveryStatus;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\AiTriage;
use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\Report;
use App\Models\Role;
use App\Models\TelegramUpdate;
use App\Services\AI\GeminiTriageService;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\DB;

/** Operations & system health overview. */
class DashboardController extends Controller
{
    public function __invoke(TelegramClient $telegram, GeminiTriageService $ai)
    {
        $roles = Role::withCount('users')->get()->pluck('users_count', 'name');
        $since = now()->subDay();

        return view('admin.overview', [
            'roles' => $roles,
            'reports' => [
                'total' => Report::count(),
                'today' => Report::where('submitted_at', '>=', $since)->count(),
                'triage_review' => Report::where('status', 'TRIAGE_REVIEW')->count(),
                'open_escalations' => Report::whereIn('escalation_state', ['OPEN', 'IN_REVIEW'])->count(),
            ],
            'health' => [
                'queue_pending' => DB::table('jobs')->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'notif_failed_24h' => NotificationLog::where('created_at', '>=', $since)
                    ->whereIn('delivery_status', [DeliveryStatus::FAILED->value, DeliveryStatus::UNDELIVERABLE->value])->count(),
                'notif_sent_24h' => NotificationLog::where('created_at', '>=', $since)
                    ->whereIn('delivery_status', [DeliveryStatus::SENT->value, DeliveryStatus::SIMULATED->value])->count(),
                'triage_failed_24h' => AiTriage::where('created_at', '>=', $since)->where('outcome', '!=', 'success')->count(),
                'triage_total_24h' => AiTriage::where('created_at', '>=', $since)->count(),
                'updates_failed' => TelegramUpdate::where('status', 'FAILED')->count(),
                'last_update' => TelegramUpdate::latest('id')->value('created_at'),
                'telegram_mode' => $telegram->configured() ? 'live' : 'simulated (no TELEGRAM_BOT_TOKEN)',
                'ai_driver' => $ai->driver(),
                'unlinked_officers' => \App\Models\User::withRole(RoleName::FOCAL_POINT)->whereDoesntHave('telegramAccount')->count(),
            ],
            'recentAudit' => AuditLog::with('actor')->latest('id')->limit(12)->get(),
        ]);
    }
}

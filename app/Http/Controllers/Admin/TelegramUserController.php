<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\TelegramAccount;
use App\Services\AuditService;
use App\Support\Fmt;
use Illuminate\Http\Request;

/** Admin-only directory of every account connected to the Telegram bot. */
class TelegramUserController extends Controller
{
    public function __construct(private AuditService $audit) {}

    private function query(Request $request)
    {
        return TelegramAccount::query()
            ->with('user.roles')
            ->withCount([
                'reports',
                'activeAssignments as active_assignments_count',
            ])
            ->when($request->role, fn ($q, $v) => $q->whereHas('user.roles', fn ($r) => $r->where('name', $v)))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->activity === '7d', fn ($q) => $q->where('last_seen_at', '>=', now()->subDays(7)))
            ->when($request->activity === 'inactive', fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subDays(30))))
            ->when($request->q, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('username', 'like', '%'.ltrim($v, '@').'%')
                ->orWhere('first_name', 'like', "%{$v}%")
                ->orWhere('telegram_user_id', 'like', "{$v}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$v}%"))))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('linked_at');
    }

    public function index(Request $request)
    {
        $all = TelegramAccount::with('user.roles')->get(['id', 'user_id', 'status', 'last_seen_at']);
        $byRole = fn (string $role) => $all->filter(fn ($a) => $a->user?->roles->contains('name', $role))->count();

        return view('admin.telegram-users', [
            'accounts' => $this->query($request)->paginate(30)->withQueryString(),
            'roles' => Role::all(),
            'stats' => [
                'total' => $all->count(),
                'reporters' => $byRole('reporter'),
                'focal_points' => $byRole('focal_point'),
                'district' => $byRole('district_officer'),
                'blocked' => $all->where('status', 'blocked')->count(),
                'active_7d' => $all->filter(fn ($a) => $a->last_seen_at?->gte(now()->subDays(7)))->count(),
            ],
        ]);
    }

    public function export(Request $request)
    {
        $query = $this->query($request);
        $this->audit->record('telegram_users.exported', null, $request->user(), null, null, ['filters' => $request->query()]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['account_name', 'roles', 'officer_type', 'telegram_user_id', 'username', 'telegram_first_name',
                'status', 'linked_at_local', 'last_seen_local', 'reports', 'active_assignments']);
            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $a) {
                    fputcsv($out, [
                        $a->user?->name, $a->user?->roles->pluck('name')->implode('|'), $a->user?->officer_type?->value,
                        $a->telegram_user_id, $a->username ? '@'.$a->username : '', $a->first_name, $a->status,
                        Fmt::dt($a->linked_at, 'Y-m-d H:i'), Fmt::dt($a->last_seen_at, 'Y-m-d H:i'),
                        $a->reports_count, $a->active_assignments_count,
                    ]);
                }
            });
            fclose($out);
        }, 'cohers-telegram-users-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

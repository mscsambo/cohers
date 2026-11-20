<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Jobs\CheckOverdueAssignmentsJob;
use App\Models\NotificationLog;
use App\Models\TelegramUpdate;
use App\Services\AuditService;
use App\Services\Notification\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Alerts & Jobs: delivery failures, failed jobs, queue depth. */
class JobsController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request)
    {
        $status = $request->input('status', 'problems');

        $logs = NotificationLog::with('recipient', 'report:id,reference_code')
            ->when($status === 'problems', fn ($q) => $q->whereIn('delivery_status', [DeliveryStatus::FAILED->value, DeliveryStatus::UNDELIVERABLE->value]))
            ->when(! in_array($status, ['problems', 'all'], true), fn ($q) => $q->where('delivery_status', $status))
            ->latest('id')->paginate(30)->withQueryString();

        return view('admin.jobs', [
            'logs' => $logs,
            'status' => $status,
            'counts' => NotificationLog::selectRaw('delivery_status, count(*) as n')->groupBy('delivery_status')->pluck('n', 'delivery_status'),
            'failedJobs' => DB::table('failed_jobs')->latest('id')->limit(30)->get(),
            'queueDepth' => DB::table('jobs')->count(),
            'failedUpdates' => TelegramUpdate::where('status', 'FAILED')->latest('id')->limit(15)->get(),
        ]);
    }

    public function retryNotification(Request $request, NotificationLog $log, NotificationService $notify)
    {
        if ($log->chat_id || $log->recipient?->telegramChatId()) {
            $log->update(['chat_id' => $log->chat_id ?: $log->recipient->telegramChatId()]);
            $notify->retry($log);
            $this->audit->record('notification.retried', $log, $request->user());

            return back()->with('status', 'Notification re-queued.');
        }

        return back()->withErrors(['retry' => 'Recipient still has no linked Telegram chat.']);
    }

    public function retryJob(Request $request, string $uuid)
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->audit->record('job.retried', null, $request->user(), null, null, ['uuid' => $uuid]);

        return back()->with('status', 'Job pushed back onto the queue.');
    }

    public function forgetJob(Request $request, string $uuid)
    {
        Artisan::call('queue:forget', ['id' => $uuid]);
        $this->audit->record('job.forgotten', null, $request->user(), null, null, ['uuid' => $uuid]);

        return back()->with('status', 'Failed job removed.');
    }

    public function runOverdue(Request $request)
    {
        CheckOverdueAssignmentsJob::dispatchSync();
        $this->audit->record('job.overdue_check_run', null, $request->user());

        return back()->with('status', 'Overdue check executed.');
    }
}

<?php

namespace App\Services\Notification;

use App\Enums\DeliveryStatus;
use App\Enums\RoleName;
use App\Jobs\SendTelegramNotificationJob;
use App\Models\NotificationLog;
use App\Models\Report;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;

/**
 * Builds, queues, retries and audits Telegram notices (section 09). Every
 * notice carries an idempotency key so repeats never double-send.
 */
class NotificationService
{
    /**
     * @param  string|Closure(string $locale): string  $text
     * @param  array|null  $keyboard  Telegram inline_keyboard rows
     */
    public function toUser(User $user, string $type, string|Closure $text, string $key, ?Report $report = null, ?array $keyboard = null): ?NotificationLog
    {
        $user->loadMissing('telegramAccount');
        $locale = $user->locale();
        $body = $text instanceof Closure ? $text($locale) : $text;
        $chatId = $user->telegramChatId();
        $idemKey = mb_substr($key.':u'.$user->id, 0, 120);

        if (NotificationLog::where('idempotency_key', $idemKey)->exists()) {
            return null; // already queued/sent for this key
        }

        try {
            $log = NotificationLog::create([
                'report_id' => $report?->id,
                'recipient_id' => $user->id,
                'chat_id' => $chatId,
                'channel' => 'telegram',
                'message_type' => $type,
                'idempotency_key' => $idemKey,
                'payload' => ['text' => $body, 'keyboard' => $keyboard],
                'delivery_status' => $chatId ? DeliveryStatus::PENDING : DeliveryStatus::UNDELIVERABLE,
                'error' => $chatId ? null : 'Recipient has no active linked Telegram chat.',
            ]);
        } catch (QueryException $e) {
            if (NotificationLog::where('idempotency_key', $idemKey)->exists()) {
                return null; // lost a race with a concurrent identical notice
            }
            throw $e;
        }

        if ($chatId) {
            SendTelegramNotificationJob::dispatch($log->id);
        }

        return $log;
    }

    /** Notify every district officer explicitly scoped to the report's district. */
    public function toDistrict(Report $report, string $type, string|Closure $text, string $key, ?array $keyboard = null): int
    {
        $officers = $this->districtOfficers($report);
        foreach ($officers as $officer) {
            $this->toUser($officer, $type, $text, $key, $report, $keyboard);
        }

        if ($officers->isEmpty()) {
            NotificationLog::firstOrCreate(['idempotency_key' => mb_substr($key.':no-district', 0, 120)], [
                'report_id' => $report->id,
                'channel' => 'telegram',
                'message_type' => $type,
                'payload' => ['text' => $text instanceof Closure ? $text('en') : $text],
                'delivery_status' => DeliveryStatus::UNDELIVERABLE,
                'error' => 'No active district officer is scoped to this district.',
            ]);
        }

        // Administrators who opted in also receive escalations (monitoring only).
        foreach ($this->admins('escalation', $officers->pluck('id')->all()) as $admin) {
            $this->toUser($admin, $type, $text, $key, $report, $keyboard);
        }

        return $officers->count();
    }

    /**
     * Informational notice to dashboard users who follow this report: district
     * officers scoped to its district plus administrators, filtered by their
     * notification preferences. Only users with a linked Telegram chat are
     * messaged, so nobody else produces "undeliverable" noise.
     */
    public function toStaff(Report $report, string $event, string $type, string|Closure $text, string $key, array $excludeUserIds = []): int
    {
        $recipients = $this->districtOfficers($report)
            ->merge($this->admins($event))
            ->unique('id')
            ->reject(fn (User $u) => in_array($u->id, $excludeUserIds, true))
            ->filter(fn (User $u) => $u->wantsNotification($event) && $u->telegramChatId());

        foreach ($recipients as $u) {
            $this->toUser($u, $type, $text, $key, $report);
        }

        return $recipients->count();
    }

    /** District officers whose area (province, district or commune) contains the report. */
    public function districtOfficers(Report $report)
    {
        $province = \App\Models\GeographicUnit::whereKey($report->district_id)->value('parent_id');
        $units = array_values(array_filter([$province, $report->district_id, $report->commune_id]));

        return User::withRole(RoleName::DISTRICT_OFFICER)
            ->where('account_status', 'active')
            ->whereHas('scopes', fn ($q) => $q->whereIn('geographic_units.id', $units ?: [0]))
            ->with('telegramAccount', 'roles')
            ->get();
    }

    private function admins(string $event, array $exclude = [])
    {
        return User::withRole(RoleName::SYSTEM_ADMIN)
            ->where('account_status', 'active')
            ->whereNotIn('id', $exclude)
            ->whereHas('telegramAccount', fn ($q) => $q->where('status', 'active'))
            ->with('telegramAccount', 'roles')
            ->get()
            ->filter(fn (User $u) => $u->wantsNotification($event));
    }

    public function retry(NotificationLog $log): void
    {
        $log->update(['delivery_status' => DeliveryStatus::PENDING, 'error' => null]);
        SendTelegramNotificationJob::dispatch($log->id);
    }
}

<?php

namespace App\Services\Telegram;

use App\Models\Report;
use App\Models\ReportAssignment;
use App\Support\BotText;
use App\Support\Fmt;
use Illuminate\Support\Str;

/** Builders for scoped Telegram notices. Callback data carries IDs only; every press is reauthorized. */
class BotMessages
{
    public static function assignmentAlert(ReportAssignment $a, string $locale): array
    {
        $r = $a->report->loadMissing('category', 'location');
        $text = BotText::t('officer.alert', $locale, [
            'code' => $r->reference_code,
            'category' => $r->category?->name($locale) ?? '—',
            'priority' => $r->priority?->value ?? '—',
            'location' => Fmt::h($r->location?->fullPath()),
            'description' => Fmt::h(Str::limit($r->original_text, 400)),
            'deadline' => Fmt::dt($a->ack_due_at),
        ]);

        return [$text, self::assignmentKeyboard($a, $locale)];
    }

    public static function assignmentKeyboard(ReportAssignment $a, string $locale): array
    {
        $t = fn ($k) => BotText::t('btn.'.$k, $locale);

        return [
            [['text' => $t('ack'), 'callback_data' => "a:ack:{$a->id}"], ['text' => $t('start_verify'), 'callback_data' => "a:ver:{$a->id}"]],
            [['text' => $t('request_info'), 'callback_data' => "a:info:{$a->id}"], ['text' => $t('findings'), 'callback_data' => "a:fnd:{$a->id}"]],
            [['text' => $t('escalate'), 'callback_data' => "a:esc:{$a->id}"]],
        ];
    }

    public static function districtAlert(Report $r, string $locale, string $reason): string
    {
        $r->loadMissing('category', 'location');

        return BotText::t('district.alert', $locale, [
            'code' => $r->reference_code,
            'category' => $r->category?->name($locale) ?? '—',
            'priority' => $r->priority?->value ?? '—',
            'location' => Fmt::h($r->location?->fullPath()),
            'reason' => Fmt::h($reason),
            'url' => route('district.reports.show', $r),
        ]);
    }

    /**
     * Tells the case owner which kind of officer now handles the report. The
     * officer's name is shared only if an administrator enables it.
     */
    public static function reporterAssigned(ReportAssignment $a, string $locale): string
    {
        $r = $a->report->loadMissing('location');
        $officer = $a->officer;
        $type = $officer?->officer_type
            ? BotText::t('officer_type.'.$officer->officer_type->value, $locale)
            : BotText::t('officer_type.generic', $locale);

        $isReassignment = $r->assignments()->where('id', '<', $a->id)->exists();
        $text = BotText::t($isReassignment ? 'reporter.reassigned' : 'reporter.assigned', $locale, [
            'code' => $r->reference_code,
            'officer_type' => $type,
            'location' => Fmt::h($r->location?->fullPath()),
        ]);

        if ($officer && \App\Models\SystemSetting::get_value('reporter.share_officer_name', false)) {
            $text .= "\n".BotText::t('reporter.officer_name', $locale, ['name' => Fmt::h($officer->name)]);
        }

        return $text;
    }

    /** Internal notice for district officers / admins: who got the case and how. */
    public static function staffAssignment(ReportAssignment $a, string $locale, ?\App\Models\User $by = null): string
    {
        $r = $a->report->loadMissing('category', 'location');
        $officer = $a->officer;
        $how = $by ? BotText::t('staff.by_user', $locale, ['name' => Fmt::h($by->name)])
            : BotText::t('staff.by_'.($a->level === 'backup' ? 'backup' : 'auto'), $locale);

        return BotText::t('staff.assignment', $locale, [
            'code' => $r->reference_code,
            'category' => $r->category?->name($locale) ?? '—',
            'priority' => $r->priority?->value ?? '—',
            'location' => Fmt::h($r->location?->fullPath()),
            'officer' => Fmt::h($officer?->name ?? '—'),
            'type' => $officer?->officer_type?->value ?? '',
            'how' => $how,
            'deadline' => Fmt::dt($a->ack_due_at),
            'url' => route('district.reports.show', $r),
        ]);
    }

    /** Closure notice: outcome in neutral words, plus the district's optional note to the reporter. */
    public static function reporterClosed(Report $r, \App\Enums\ReportStatus $outcome, ?string $note, string $locale): string
    {
        $key = match ($outcome) {
            \App\Enums\ReportStatus::VERIFIED => 'verified',
            \App\Enums\ReportStatus::DUPLICATE => 'duplicate',
            default => 'not_confirmed',
        };
        $text = BotText::t('reporter.closed', $locale, [
            'code' => $r->reference_code,
            'outcome' => BotText::t('reporter.outcome.'.$key, $locale),
        ]);
        if ($note) {
            $text .= "\n\n".BotText::t('reporter.closed_note', $locale, ['note' => Fmt::h($note)]);
        }

        return $text."\n\n".BotText::t('reporter.closed_thanks', $locale);
    }

    public static function reporterAcknowledged(Report $r, string $locale): string
    {
        return BotText::t('reporter.acknowledged', $locale, ['code' => $r->reference_code]);
    }

    public static function reporterStatus(Report $r, string $locale): string
    {
        return BotText::t('reporter.status_update', $locale, [
            'code' => $r->reference_code,
            'status' => $r->reporterStatusLabel($locale),
        ]);
    }
}

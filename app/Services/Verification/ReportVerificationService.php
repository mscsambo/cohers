<?php

namespace App\Services\Verification;

use App\Enums\AssignmentStatus;
use App\Enums\EscalationTrigger;
use App\Enums\ReportStatus;
use App\Enums\VerificationOutcome;
use App\Models\Report;
use App\Models\ReportAssignment;
use App\Models\ReportInformationRequest;
use App\Models\ReportVerification;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Escalation\EscalationService;
use App\Services\Notification\NotificationService;
use App\Services\Reporting\ReportDuplicateService;
use App\Services\Reporting\ReportService;
use App\Services\Telegram\BotMessages;
use App\Support\BotText;
use App\Support\Fmt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Focal-point actions (section 07). Every call reauthorizes the actor against
 * the assignment in the database — callback payloads are never trusted.
 * Repeated calls are safe and never duplicate decisions.
 */
class ReportVerificationService
{
    public function __construct(
        private ReportService $reports,
        private EscalationService $escalations,
        private NotificationService $notify,
        private ReportDuplicateService $duplicates,
        private AuditService $audit,
    ) {}

    public function authorize(ReportAssignment $assignment, User $officer): void
    {
        $assignment->loadMissing('report.category');
        $sectorType = $assignment->report->category?->sector?->officerType();

        if ($assignment->focal_point_user_id !== $officer->id
            || ! $officer->isActive()
            || ! $officer->isFocalPoint()
            || ! $assignment->isActive()
            || ($sectorType && $officer->officer_type !== $sectorType)) {
            throw new AuthorizationException('You are not authorized to act on this assignment.');
        }
    }

    /** @return bool true if newly acknowledged, false if it already was */
    public function acknowledge(ReportAssignment $assignment, User $officer, string $channel = 'telegram'): bool
    {
        $this->authorize($assignment, $officer);
        if ($assignment->acknowledged_at) {
            return false;
        }

        DB::transaction(function () use ($assignment, $officer, $channel) {
            $assignment->update(['assignment_status' => AssignmentStatus::ACKNOWLEDGED, 'acknowledged_at' => now()]);
            $report = $assignment->report;
            if ($report->status === ReportStatus::ASSIGNED) {
                $this->reports->transition($report, ReportStatus::ACKNOWLEDGED, $officer, 'Officer acknowledged', $channel);
            }
            $this->audit->record('assignment.acknowledged', $assignment, $officer, null, null, null, $channel);
        });

        $report = $assignment->report;
        $this->notify->toUser($report->reporter, 'reporter.acknowledged',
            fn ($loc) => BotMessages::reporterAcknowledged($report, $loc), "reporter-ack:{$assignment->id}", $report);

        return true;
    }

    public function startVerification(ReportAssignment $assignment, User $officer, string $channel = 'telegram'): bool
    {
        $this->authorize($assignment, $officer);
        if ($assignment->verification_started_at) {
            return false;
        }
        $this->acknowledge($assignment, $officer, $channel);

        DB::transaction(function () use ($assignment, $officer, $channel) {
            $assignment->update(['assignment_status' => AssignmentStatus::VERIFYING, 'verification_started_at' => now()]);
            $this->reports->transition($assignment->report, ReportStatus::VERIFYING, $officer, 'Field verification started', $channel);
            $this->audit->record('assignment.verification_started', $assignment, $officer, null, null, null, $channel);
        });

        $report = $assignment->report;
        $this->notify->toUser($report->reporter, 'reporter.verifying',
            fn ($loc) => BotMessages::reporterStatus($report, $loc), "reporter-verifying:{$assignment->id}", $report);

        return true;
    }

    public function requestInformation(ReportAssignment $assignment, User $officer, string $question, string $channel = 'telegram'): ReportInformationRequest
    {
        $this->authorize($assignment, $officer);
        $question = trim($question);
        if (mb_strlen($question) < 3) {
            throw new InvalidArgumentException('Question is required.');
        }
        $this->acknowledge($assignment, $officer, $channel);

        $report = $assignment->report;
        $request = DB::transaction(function () use ($report, $officer, $question, $channel) {
            $request = ReportInformationRequest::create([
                'report_id' => $report->id,
                'requested_by' => $officer->id,
                'question' => $question,
                'status' => 'OPEN',
            ]);
            $this->reports->transition($report, ReportStatus::NEEDS_INFO, $officer, 'Clarification requested', $channel);
            $this->audit->record('report.info_requested', $report, $officer, $question, null, null, $channel);

            return $request;
        });

        $reporter = $report->reporter;
        $this->notify->toUser($reporter, 'reporter.info_request',
            fn ($loc) => BotText::t('reporter.info_request', $loc, ['code' => $report->reference_code, 'question' => Fmt::h($question)]),
            "info-req:{$request->id}", $report,
            [[['text' => BotText::t('btn.reply', $reporter->locale()), 'callback_data' => "r:reply:{$request->id}"]]]);

        return $request;
    }

    public function answerInformation(ReportInformationRequest $request, User $reporter, string $answer): bool
    {
        $request->refresh();
        $report = $request->report;
        if ($report->reporter_id !== $reporter->id) {
            throw new AuthorizationException('Not your report.');
        }
        if ($request->status !== 'OPEN') {
            return false;
        }

        DB::transaction(function () use ($request, $reporter, $answer, $report) {
            $request->update(['response' => trim($answer), 'status' => 'ANSWERED', 'responded_at' => now()]);
            $assignment = $report->activeAssignment;
            if ($report->status === ReportStatus::NEEDS_INFO
                && ! $report->informationRequests()->where('status', 'OPEN')->exists()) {
                $back = $assignment?->verification_started_at ? ReportStatus::VERIFYING : ReportStatus::ACKNOWLEDGED;
                $this->reports->transition($report, $back, $reporter, 'Reporter answered clarification', 'telegram');
            }
        });

        $this->notify->toUser($request->requester, 'officer.info_answer',
            fn ($loc) => BotText::t('officer.info_answer', $loc, [
                'code' => $report->reference_code,
                'question' => Fmt::h($request->question),
                'answer' => Fmt::h($answer),
            ]), "info-ans:{$request->id}", $report);

        return true;
    }

    /**
     * Record field findings with a final officer outcome. Notes are mandatory;
     * DUPLICATE links (never deletes) the other report under one incident.
     */
    public function submitFindings(
        ReportAssignment $assignment,
        User $officer,
        VerificationOutcome $outcome,
        string $findings,
        bool $followUpNeeded = false,
        ?Report $duplicateOf = null,
        string $channel = 'telegram',
    ): ReportVerification {
        $this->authorize($assignment, $officer);

        if (! in_array($outcome, [VerificationOutcome::VERIFIED, VerificationOutcome::REJECTED, VerificationOutcome::DUPLICATE], true)) {
            throw new InvalidArgumentException('Use request-information or escalate for that outcome.');
        }
        if (mb_strlen(trim($findings)) < 5) {
            throw new InvalidArgumentException('Findings / reason notes are required.');
        }
        if ($outcome === VerificationOutcome::DUPLICATE && (! $duplicateOf || $duplicateOf->id === $assignment->report_id)) {
            throw new InvalidArgumentException('A valid original report is required for DUPLICATE.');
        }

        $report = $assignment->report;
        $verification = DB::transaction(function () use ($assignment, $officer, $outcome, $findings, $followUpNeeded, $duplicateOf, $channel, $report) {
            if (! $assignment->acknowledged_at) {
                $assignment->acknowledged_at = now();
            }
            $assignment->fill(['assignment_status' => AssignmentStatus::COMPLETED, 'completed_at' => now()])->save();

            $verification = ReportVerification::create([
                'report_id' => $report->id,
                'assignment_id' => $assignment->id,
                'verifier_id' => $officer->id,
                'outcome' => $outcome,
                'findings' => trim($findings),
                'follow_up_needed' => $followUpNeeded,
                'duplicate_of_report_id' => $duplicateOf?->id,
                'channel' => $channel,
                'verified_at' => now(),
            ]);

            $this->reports->transition($report, ReportStatus::from($outcome->value), $officer, trim($findings), $channel);
            $this->audit->record('report.findings_submitted', $report, $officer, trim($findings), null,
                ['outcome' => $outcome->value, 'follow_up_needed' => $followUpNeeded], $channel);

            if ($duplicateOf) {
                $this->duplicates->link($report, $duplicateOf, $officer, 'Officer marked duplicate', $channel);
            }

            return $verification;
        });

        if ($followUpNeeded) {
            $this->escalations->open($report, EscalationTrigger::MANUAL, 'Officer reports further response/follow-up needed: '.trim($findings), $officer, $channel);
        }

        // District officers (and opted-in admins) learn the case now awaits their closure decision.
        $this->notify->toStaff($report, 'outcome', 'staff.findings',
            fn ($loc) => BotText::t('staff.findings', $loc, [
                'code' => $report->reference_code,
                'outcome' => $outcome->value,
                'officer' => Fmt::h($officer->name),
                'findings' => Fmt::h(\Illuminate\Support\Str::limit(trim($findings), 300)),
                'next' => BotText::t($followUpNeeded ? 'staff.next_escalation' : 'staff.next_close', $loc),
                'url' => route('district.reports.show', $report),
            ]), "staff-findings:{$verification->id}", [$officer->id]);

        $this->notify->toUser($report->reporter, 'reporter.reviewed',
            fn ($loc) => BotMessages::reporterStatus($report, $loc), "reporter-reviewed:{$verification->id}", $report);

        return $verification;
    }

    public function escalate(ReportAssignment $assignment, User $officer, string $reason, string $channel = 'telegram'): void
    {
        $this->authorize($assignment, $officer);
        if (mb_strlen(trim($reason)) < 5) {
            throw new InvalidArgumentException('An escalation reason is required.');
        }
        $this->acknowledge($assignment, $officer, $channel);

        ReportVerification::create([
            'report_id' => $assignment->report_id,
            'assignment_id' => $assignment->id,
            'verifier_id' => $officer->id,
            'outcome' => VerificationOutcome::ESCALATED,
            'findings' => trim($reason),
            'channel' => $channel,
            'verified_at' => now(),
        ]);

        $this->escalations->open($assignment->report, EscalationTrigger::MANUAL, trim($reason), $officer, $channel);
    }

    public function attachEvidence(ReportAssignment $assignment, User $officer, string $fileId): void
    {
        $this->authorize($assignment, $officer);
        $count = $assignment->report->attachments()->where('source', 'officer')->count();
        if ($count >= 10) {
            throw new InvalidArgumentException('Evidence limit reached.');
        }
        $attachment = $assignment->report->attachments()->create([
            'uploaded_by' => $officer->id,
            'source' => 'officer',
            'kind' => 'photo',
            'telegram_file_id' => $fileId,
        ]);
        \App\Jobs\DownloadTelegramMediaJob::dispatch($attachment->id);
    }
}

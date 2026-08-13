<?php

namespace App\Modules\Encounter\Application\Services;

use App\Modules\Encounter\Application\Exceptions\EncounterCloseBlockedException;
use App\Modules\Encounter\Application\Exceptions\EncounterOwnerConflictException;
use App\Modules\Encounter\Application\Exceptions\InvalidEncounterStatusTransitionException;
use App\Modules\Encounter\Application\UseCases\GetEncounterCloseReadinessUseCase;
use App\Modules\Encounter\Domain\Repositories\EncounterAuditLogRepositoryInterface;
use App\Modules\Encounter\Domain\ValueObjects\EncounterDiagnosisType;
use App\Modules\Encounter\Domain\ValueObjects\EncounterStatus;
use App\Modules\Encounter\Infrastructure\Models\EncounterDiagnosisModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;

class EncounterLifecycleService
{
    // C-5 (reports/clinical-note-audit/15-critical-system-integrity-review.md),
    // acknowledgement-quality fix (decided 2026-07-08): a bare non-empty check
    // let "n/a" (3 characters) satisfy the close-out reason requirement — not
    // an acknowledgement, just a keystroke tax. This does not make close
    // blocking; it makes the required reason actually mean something.
    private const MIN_CLOSE_REASON_LENGTH = 10;

    /**
     * @var array<int, string>
     */
    private const TRIVIAL_CLOSE_REASONS = [
        'n/a', 'na', 'none', 'ok', 'okay', 'done', 'pending', 'tbd', 'asap', 'fine', 'nil',
    ];

    public function __construct(
        private readonly GetEncounterCloseReadinessUseCase $encounterCloseReadinessUseCase,
        private readonly EncounterAuditLogRepositoryInterface $encounterAuditLogRepository,
    ) {}
    public function findById(string $encounterId): ?EncounterModel
    {
        $normalizedId = trim($encounterId);

        return $normalizedId !== ''
            ? EncounterModel::query()->find($normalizedId)
            : null;
    }

    public function markInProgress(string $encounterId, ?int $actorId = null): ?EncounterModel
    {
        $encounter = $this->findById($encounterId);
        if ($encounter === null) {
            return null;
        }

        if ($encounter->status === EncounterStatus::CLOSED->value) {
            return $encounter;
        }

        if ($encounter->status === EncounterStatus::OPENED->value) {
            $previousStatus = (string) $encounter->status;
            $encounter->status = EncounterStatus::IN_PROGRESS->value;
            $encounter->save();

            $this->writeAudit(
                encounterId: $encounterId,
                action: 'encounter.status.updated',
                actorId: $actorId,
                changes: [
                    'before' => ['status' => $previousStatus],
                    'after' => ['status' => EncounterStatus::IN_PROGRESS->value],
                ],
                metadata: ['source' => 'medical_record_update'],
            );
        }

        return $encounter->fresh();
    }

    public function syncFromMedicalRecordStatus(
        string $encounterId,
        string $medicalRecordStatus,
        ?string $reason = null,
        ?int $actorId = null,
    ): ?EncounterModel {
        $encounter = $this->findById($encounterId);
        if ($encounter === null) {
            return null;
        }

        if ($encounter->status === EncounterStatus::CLOSED->value) {
            return $encounter;
        }

        $nextStatus = match (strtolower(trim($medicalRecordStatus))) {
            MedicalRecordStatus::FINALIZED->value => EncounterStatus::SIGNED->value,
            MedicalRecordStatus::AMENDED->value => EncounterStatus::AMENDED->value,
            MedicalRecordStatus::DRAFT->value => EncounterStatus::IN_PROGRESS->value,
            default => null,
        };

        if ($nextStatus === null || $encounter->status === $nextStatus) {
            return $encounter;
        }

        $previousStatus = (string) $encounter->status;

        if (
            $encounter->status === EncounterStatus::OPENED->value
            && $nextStatus === EncounterStatus::SIGNED->value
        ) {
            $encounter->status = EncounterStatus::IN_PROGRESS->value;
        }

        // Preserve ready_for_sign when the note moves back to draft (doctor re-editing).
        // The results are still available for review — don't downgrade the encounter signal.
        if (
            $encounter->status === EncounterStatus::READY_FOR_SIGN->value
            && $nextStatus === EncounterStatus::IN_PROGRESS->value
        ) {
            $encounter->status = EncounterStatus::READY_FOR_SIGN->value;
        } else {
            $encounter->status = $nextStatus;
        }

        if ($reason !== null && trim($reason) !== '') {
            $encounter->status_reason = trim($reason);
        }

        $encounter->save();

        $this->writeAudit(
            encounterId: $encounterId,
            action: 'encounter.status.updated',
            actorId: $actorId,
            changes: [
                'before' => ['status' => $previousStatus],
                'after' => [
                    'status' => (string) $encounter->status,
                    'status_reason' => $encounter->status_reason,
                ],
            ],
            metadata: [
                'source' => 'medical_record_status',
                'medical_record_status' => strtolower(trim($medicalRecordStatus)),
            ],
        );

        return $encounter->fresh();
    }

    /**
     * Keeps the encounter's structured diagnoses list in sync with the note's
     * single diagnosisCode field whenever a note is signed (finalized, or
     * re-finalized into amended) — that's the moment the clinician commits to
     * the documentation, not while it's still a draft. Idempotent: re-signing
     * the same code repeatedly does not create duplicate rows, it only
     * (re-)promotes that code to primary, demoting whatever was primary
     * before it. Deliberately does not run for admission/discharge/progress
     * notes without a code, or for draft saves.
     */
    public function syncPrimaryDiagnosisFromMedicalRecord(
        string $encounterId,
        ?string $diagnosisCode,
        ?int $actorId,
    ): void {
        $normalizedCode = strtoupper(trim((string) $diagnosisCode));
        if ($normalizedCode === '') {
            return;
        }

        $matching = EncounterDiagnosisModel::query()
            ->where('encounter_id', $encounterId)
            ->get()
            ->first(
                static fn (EncounterDiagnosisModel $diagnosis): bool => strtoupper(trim((string) $diagnosis->diagnosis_code)) === $normalizedCode,
            );

        if ($matching !== null && $matching->diagnosis_type === EncounterDiagnosisType::PRIMARY->value) {
            return;
        }

        EncounterDiagnosisModel::query()
            ->where('encounter_id', $encounterId)
            ->where('diagnosis_type', EncounterDiagnosisType::PRIMARY->value)
            ->update(['diagnosis_type' => EncounterDiagnosisType::SECONDARY->value]);

        if ($matching !== null) {
            $matching->diagnosis_type = EncounterDiagnosisType::PRIMARY->value;
            $matching->save();

            return;
        }

        EncounterDiagnosisModel::query()->create([
            'encounter_id' => $encounterId,
            'diagnosis_code' => $normalizedCode,
            'diagnosis_description' => null,
            'diagnosis_type' => EncounterDiagnosisType::PRIMARY->value,
            'recorded_by_user_id' => $actorId,
            'recorded_at' => now(),
        ]);
    }

    public function markReadyForSign(
        string $encounterId,
        ?string $reason = null,
        ?int $actorId = null,
    ): ?EncounterModel {
        $encounter = $this->findById($encounterId);
        if ($encounter === null) {
            return null;
        }

        if ($encounter->status === EncounterStatus::CLOSED->value) {
            return $encounter;
        }

        if ($encounter->status === EncounterStatus::READY_FOR_SIGN->value) {
            return $encounter;
        }

        $allowedFrom = [
            EncounterStatus::IN_PROGRESS->value,
            EncounterStatus::OPENED->value,
            EncounterStatus::SIGNED->value,
            EncounterStatus::AMENDED->value,
        ];

        if (! in_array($encounter->status, $allowedFrom, true)) {
            throw new InvalidEncounterStatusTransitionException(
                (string) $encounter->status,
                EncounterStatus::READY_FOR_SIGN->value,
            );
        }

        $previousStatus = (string) $encounter->status;
        $encounter->status = EncounterStatus::READY_FOR_SIGN->value;

        if ($reason !== null && trim($reason) !== '') {
            $encounter->status_reason = trim($reason);
        }

        $encounter->save();

        $this->writeAudit(
            encounterId: $encounterId,
            action: 'encounter.status.updated',
            actorId: $actorId,
            changes: [
                'before' => ['status' => $previousStatus],
                'after' => [
                    'status' => EncounterStatus::READY_FOR_SIGN->value,
                    'status_reason' => $encounter->status_reason,
                ],
            ],
            metadata: [
                'source' => 'order_results_review_ready',
            ],
        );

        return $encounter->fresh();
    }

    public function close(
        string $encounterId,
        ?string $reason,
        ?int $actorId,
        bool $acknowledgeCloseGaps = false,
        ?string $disposition = null,
        ?string $dispositionNotes = null,
        bool $isFacilitySuperAdmin = false,
    ): ?EncounterModel {
        $encounter = $this->findById($encounterId);
        if ($encounter === null) {
            return null;
        }

        $this->assertEncounterOwnership($encounter, $actorId, $isFacilitySuperAdmin);

        $currentStatus = strtolower(trim((string) $encounter->status));
        if ($currentStatus === EncounterStatus::CLOSED->value) {
            return $encounter;
        }

        if (! in_array($currentStatus, [
            EncounterStatus::OPENED->value,
            EncounterStatus::SIGNED->value,
            EncounterStatus::AMENDED->value,
            EncounterStatus::IN_PROGRESS->value,
            EncounterStatus::READY_FOR_SIGN->value,
        ], true)) {
            throw new InvalidEncounterStatusTransitionException(
                $currentStatus,
                EncounterStatus::CLOSED->value,
            );
        }

        $readiness = $this->encounterCloseReadinessUseCase->execute($encounterId, dispositionOverride: $disposition);
        if (is_array($readiness)) {
            if (! (bool) ($readiness['canClose'] ?? false)) {
                throw new EncounterCloseBlockedException(
                    'Encounter close is blocked until required close-out items are resolved.',
                    $readiness,
                );
            }

            if (
                (bool) ($readiness['requiresAcknowledgement'] ?? false)
                && ! $acknowledgeCloseGaps
            ) {
                throw new EncounterCloseBlockedException(
                    'Review close-out warnings and acknowledge them before closing this encounter.',
                    $readiness,
                );
            }

            if (
                (bool) ($readiness['requiresAcknowledgement'] ?? false)
                && $acknowledgeCloseGaps
                && ! $this->isMeaningfulCloseReason($reason)
            ) {
                throw new EncounterCloseBlockedException(
                    sprintf(
                        'Provide a specific close-out reason (at least %d characters) when acknowledging close warnings — "n/a" or similar placeholders are not accepted.',
                        self::MIN_CLOSE_REASON_LENGTH,
                    ),
                    $readiness,
                );
            }
        }

        $encounter->status = EncounterStatus::CLOSED->value;
        $encounter->closed_at = now();
        $encounter->status_reason = $reason !== null && trim($reason) !== ''
            ? trim($reason)
            : $encounter->status_reason;
        $encounter->disposition = $disposition !== null && trim($disposition) !== ''
            ? trim($disposition)
            : $encounter->disposition;
        $encounter->disposition_notes = $dispositionNotes !== null && trim($dispositionNotes) !== ''
            ? trim($dispositionNotes)
            : $encounter->disposition_notes;

        if ($actorId !== null && (int) ($encounter->primary_clinician_user_id ?? 0) <= 0) {
            $encounter->primary_clinician_user_id = $actorId;
        }

        $encounter->save();

        $this->writeAudit(
            encounterId: $encounterId,
            action: 'encounter.closed',
            actorId: $actorId,
            changes: [
                'before' => ['status' => $currentStatus],
                'after' => [
                    'status' => EncounterStatus::CLOSED->value,
                    'closed_at' => $encounter->closed_at?->toISOString(),
                    'status_reason' => $encounter->status_reason,
                    'disposition' => $encounter->disposition,
                ],
            ],
            metadata: [
                'acknowledge_close_gaps' => $acknowledgeCloseGaps,
                'close_readiness' => is_array($readiness) ? [
                    'blocking_count' => (int) ($readiness['blockingCount'] ?? 0),
                    'warning_count' => (int) ($readiness['warningCount'] ?? 0),
                    // C-5 acknowledgement-quality fix: counts alone don't let a
                    // later investigation reconstruct what was actually
                    // outstanding at the moment of close — record the specific
                    // item IDs, not just how many there were.
                    'outstanding_items' => $this->outstandingItemIdsByWarningItem($readiness),
                ] : null,
            ],
        );

        return $encounter->fresh();
    }

    /**
     * C-5 acknowledgement-quality fix: a bare non-empty check let "n/a"
     * satisfy the requirement. This requires a minimum length and rejects a
     * short list of common placeholders (normalized: lowercased, trimmed,
     * trailing punctuation stripped) — a floor on meaningfulness, not an
     * attempt to fully judge reason quality, which no fixed rule can do.
     */
    private function isMeaningfulCloseReason(?string $reason): bool
    {
        $trimmed = trim((string) $reason);
        if (mb_strlen($trimmed) < self::MIN_CLOSE_REASON_LENGTH) {
            return false;
        }

        $normalized = strtolower(trim($trimmed, " \t\n\r\0\x0B.!"));

        return ! in_array($normalized, self::TRIVIAL_CLOSE_REASONS, true);
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @return array<string, array<int, string>>
     */
    private function outstandingItemIdsByWarningItem(array $readiness): array
    {
        $items = is_array($readiness['items'] ?? null) ? $readiness['items'] : [];
        $outstanding = [];

        foreach ($items as $item) {
            if (($item['severity'] ?? '') !== 'warn' || ($item['status'] ?? '') !== 'fail') {
                continue;
            }

            $details = is_array($item['details'] ?? null) ? $item['details'] : [];
            $outstanding[(string) ($item['id'] ?? 'unknown')] = array_values(array_filter(array_map(
                static fn (array $detail): ?string => isset($detail['id']) ? (string) $detail['id'] : null,
                $details,
            )));
        }

        return $outstanding;
    }

    /**
     * Administrative cancellation for a visit that never received any
     * clinical attention — reception cancelling a check-in before triage,
     * not a clinical close-out (bug found & fixed 2026-08-11: cancelling the
     * appointment left its Encounter permanently stuck at OPENED, which the
     * Patient Profile's "Latest visit" card renders as "In progress"
     * forever, since only `status === 'closed'` maps to "complete").
     *
     * Deliberately narrower than close(): only OPENED (untouched — no
     * medical record, vitals, or order has moved it to IN_PROGRESS or
     * beyond) is eligible, and there is no close-readiness gate, since
     * nothing clinical exists yet to check readiness on. Anything already
     * past OPENED is left completely alone and this returns it unchanged —
     * a visit with real documentation must go through close(), never be
     * silently discarded as a side effect of an administrative cancel.
     */
    public function cancel(
        string $encounterId,
        ?string $reason,
        ?int $actorId,
    ): ?EncounterModel {
        $encounter = $this->findById($encounterId);
        if ($encounter === null) {
            return null;
        }

        if ($encounter->status !== EncounterStatus::OPENED->value) {
            return $encounter;
        }

        $previousStatus = (string) $encounter->status;
        $encounter->status = EncounterStatus::CANCELLED->value;
        $encounter->status_reason = $reason !== null && trim($reason) !== ''
            ? trim($reason)
            : $encounter->status_reason;
        $encounter->save();

        $this->writeAudit(
            encounterId: $encounterId,
            action: 'encounter.cancelled',
            actorId: $actorId,
            changes: [
                'before' => ['status' => $previousStatus],
                'after' => [
                    'status' => EncounterStatus::CANCELLED->value,
                    'status_reason' => $encounter->status_reason,
                ],
            ],
            metadata: ['source' => 'appointment_cancelled'],
        );

        return $encounter->fresh();
    }

    public function reopen(
        string $encounterId,
        string $reason,
        ?int $actorId,
        bool $isFacilitySuperAdmin = false,
    ): ?EncounterModel {
        $encounter = $this->findById($encounterId);
        if ($encounter === null) {
            return null;
        }

        $this->assertEncounterOwnership($encounter, $actorId, $isFacilitySuperAdmin);

        $currentStatus = strtolower(trim((string) $encounter->status));
        if ($currentStatus !== EncounterStatus::CLOSED->value) {
            throw new InvalidEncounterStatusTransitionException(
                $currentStatus,
                EncounterStatus::IN_PROGRESS->value,
            );
        }

        $encounter->status = EncounterStatus::IN_PROGRESS->value;
        $encounter->closed_at = null;
        $encounter->status_reason = trim($reason);

        if ($actorId !== null) {
            $encounter->primary_clinician_user_id = $actorId;
        }

        $encounter->save();

        $this->writeAudit(
            encounterId: $encounterId,
            action: 'encounter.reopened',
            actorId: $actorId,
            changes: [
                'before' => ['status' => $currentStatus],
                'after' => [
                    'status' => EncounterStatus::IN_PROGRESS->value,
                    'status_reason' => $encounter->status_reason,
                ],
            ],
        );

        return $encounter->fresh();
    }

    /**
     * Mirrors AppointmentController's consultation-ownership pattern, using
     * EncounterModel::primary_clinician_user_id as this module's own ownership
     * field (already auto-assigned by close()/reopen() below). An unset owner
     * means no one has claimed the encounter yet — anyone permitted may
     * close/reopen it, same as the bootstrap case for a fresh consultation.
     */
    private function assertEncounterOwnership(
        EncounterModel $encounter,
        ?int $actorId,
        bool $isFacilitySuperAdmin,
    ): void {
        if ($isFacilitySuperAdmin) {
            return;
        }

        $ownerUserId = (int) ($encounter->primary_clinician_user_id ?? 0);
        if ($ownerUserId <= 0 || $actorId === null || $ownerUserId === $actorId) {
            return;
        }

        throw new EncounterOwnerConflictException($ownerUserId);
    }

    private function writeAudit(
        string $encounterId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = [],
    ): void {
        $this->encounterAuditLogRepository->write(
            encounterId: $encounterId,
            action: $action,
            actorId: $actorId,
            changes: $changes,
            metadata: $metadata,
        );
    }
}

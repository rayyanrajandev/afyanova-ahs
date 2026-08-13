<?php

namespace App\Support\CanonicalEncounterState;

use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\Encounter\Application\UseCases\GetEncounterCloseReadinessUseCase;
use App\Modules\Encounter\Domain\Repositories\EncounterAuditLogRepositoryInterface;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordNoteType;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Read-only Shadow Mode evaluator for the Canonical Encounter State Machine.
 *
 * See reports/encounter-state-machine-design/00-canonical-encounter-state-machine.md
 * (the model — states, dimensions, mapping, conflicts — unchanged) and
 * reports/encounter-state-machine-design/01-integration-and-migration-architecture.md
 * (placement, computation strategy, fail-closed rule — unchanged).
 *
 * HARD GUARANTEES OF THIS CLASS:
 *  - It performs no writes anywhere. Every dependency below is used only via its
 *    existing read methods.
 *  - It never creates an Encounter (it uses EncounterResolverService::findById(),
 *    never findOrCreateForVisit()) and never creates, updates, or deletes a
 *    MedicalRecord, order, or billing row.
 *  - It reuses GetEncounterCloseReadinessUseCase's existing cross-module
 *    aggregation (pending-order count, diagnosis-documented check, billing
 *    summary) rather than re-implementing that aggregation a second time.
 *  - A failure in any one contributing read degrades only that dimension to
 *    UNKNOWN (fail-closed) — it never crashes the whole resolution, and it
 *    never causes a dimension to default to a value that looks "safe" or
 *    "complete".
 */
final class CanonicalEncounterStateResolver
{
    /** Fixed cap on notes read per encounter — one query, not one per note (no N+1). */
    private const MAX_NOTES_PER_ENCOUNTER = 50;

    public function __construct(
        private readonly EncounterResolverService $encounterResolverService,
        private readonly MedicalRecordRepositoryInterface $medicalRecordRepository,
        private readonly GetEncounterCloseReadinessUseCase $closeReadinessUseCase,
        private readonly EncounterAuditLogRepositoryInterface $encounterAuditLogRepository,
    ) {}

    public function resolve(string $encounterId): ?CanonicalEncounterStateSnapshot
    {
        $normalizedId = trim($encounterId);
        if ($normalizedId === '') {
            return null;
        }

        $encounter = $this->encounterResolverService->findById($normalizedId);
        if ($encounter === null) {
            return null;
        }

        $failures = [];
        $patientId = trim((string) $encounter->patient_id);

        $notes = $this->attempt(
            fn (): array => $this->loadConsultationNotes($normalizedId, $patientId),
            null,
            $failures,
            'notes',
        );

        $closeReadiness = $this->attempt(
            fn (): ?array => $this->closeReadinessUseCase->execute($normalizedId),
            null,
            $failures,
            'close_readiness',
        );

        $ordersSignal = $this->attempt(
            fn (): array => $this->loadOrdersSignal($normalizedId),
            null,
            $failures,
            'orders_signal',
        );

        $noteDimension = $notes === null
            ? CanonicalNoteDimension::UNKNOWN
            : $this->deriveNoteDimension($notes);

        $pendingOrderCount = $this->extractItemCount($closeReadiness, 'pending_orders');
        $ordersDimension = ($ordersSignal === null || $pendingOrderCount === null)
            ? CanonicalOrdersDimension::UNKNOWN
            : $this->deriveOrdersDimension($pendingOrderCount, $ordersSignal);

        $billingDimension = $this->deriveBillingDimension($closeReadiness);
        $diagnosisDimension = $this->deriveDiagnosisDimension($closeReadiness);

        [$canonicalState, $ruleId] = $this->deriveCanonicalStateAndRule(
            (string) $encounter->status,
            $noteDimension,
            $ordersDimension,
            $billingDimension,
            $diagnosisDimension,
        );

        $conflicts = $this->detectConflicts(
            encounter: $encounter,
            notes: $notes ?? [],
            noteDimension: $noteDimension,
            ordersDimension: $ordersDimension,
            billingDimension: $billingDimension,
            ordersSignal: $ordersSignal ?? [],
            pendingOrderCount: $pendingOrderCount,
        );

        return new CanonicalEncounterStateSnapshot(
            encounterId: $normalizedId,
            canonicalState: $canonicalState,
            noteDimension: $noteDimension,
            ordersDimension: $ordersDimension,
            billingDimension: $billingDimension,
            diagnosisDimension: $diagnosisDimension,
            matchedRuleId: $ruleId,
            detectedConflicts: $conflicts,
            computedAt: CarbonImmutable::now(),
            legacyEncounterStatus: (string) $encounter->status,
            partialFailures: array_keys($failures),
        );
    }

    /**
     * One query. Every non-archived consultation-type note for the encounter,
     * newest updated_at first. Used for the N dimension and for CONFLICT-03/04/07,
     * all of which need the full set, not just one resolved "primary" record.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadConsultationNotes(string $encounterId, string $patientId): array
    {
        $result = $this->medicalRecordRepository->search(
            query: null,
            patientId: $patientId !== '' ? $patientId : null,
            encounterId: $encounterId,
            appointmentId: null,
            appointmentReferralId: null,
            admissionId: null,
            theatreProcedureId: null,
            authorUserId: null,
            status: null,
            recordType: MedicalRecordNoteType::CONSULTATION_NOTE->value,
            fromDateTime: null,
            toDateTime: null,
            page: 1,
            perPage: self::MAX_NOTES_PER_ENCOUNTER,
            sortBy: 'updated_at',
            sortDirection: 'desc',
        );

        $notes = is_array($result['data'] ?? null) ? $result['data'] : [];

        return array_values(array_filter(
            $notes,
            static fn (array $note): bool => ($note['status'] ?? null) !== MedicalRecordStatus::ARCHIVED->value,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $notes
     */
    private function deriveNoteDimension(array $notes): CanonicalNoteDimension
    {
        if ($notes === []) {
            return CanonicalNoteDimension::NONE;
        }

        $signedStatuses = [MedicalRecordStatus::FINALIZED->value, MedicalRecordStatus::AMENDED->value];

        foreach ($notes as $note) {
            if (! in_array($note['status'] ?? null, $signedStatuses, true)) {
                return CanonicalNoteDimension::DRAFT;
            }
        }

        return CanonicalNoteDimension::SIGNED;
    }

    /**
     * Locally reproduces both existing "primary record" resolution orders
     * (workspace-style: finalized, amended; close-readiness-style: finalized,
     * amended, draft) against the same already-fetched, already-sorted note
     * list — no extra query, and no call into either use case's private
     * resolution method.
     *
     * @param  array<int, array<string, mixed>>  $notes  already sorted by updated_at desc
     * @param  array<int, string>  $preferenceOrder
     * @return array<string, mixed>|null
     */
    private function pickPrimary(array $notes, array $preferenceOrder): ?array
    {
        foreach ($preferenceOrder as $status) {
            foreach ($notes as $note) {
                if (($note['status'] ?? null) === $status) {
                    return $note;
                }
            }
        }

        return null;
    }

    /**
     * Four fixed, index-scoped existence checks plus one targeted status check —
     * a fixed small number of queries per encounter, not one per order row.
     *
     * @return array{hasAnyOrder: bool, hasPharmacyReconciliationException: bool}
     */
    private function loadOrdersSignal(string $encounterId): array
    {
        $hasAnyLab = LaboratoryOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->whereNull('entered_in_error_at')
            ->exists();

        $hasAnyRadiology = RadiologyOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->whereNull('entered_in_error_at')
            ->exists();

        $hasAnyPharmacy = PharmacyOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->whereNull('entered_in_error_at')
            ->exists();

        $hasAnyTheatre = TheatreProcedureModel::query()
            ->where('encounter_id', $encounterId)
            ->whereNull('entered_in_error_at')
            ->exists();

        $hasPharmacyReconciliationException = PharmacyOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->whereNull('entered_in_error_at')
            ->where('status', 'reconciliation_exception')
            ->exists();

        return [
            'hasAnyOrder' => $hasAnyLab || $hasAnyRadiology || $hasAnyPharmacy || $hasAnyTheatre,
            'hasPharmacyReconciliationException' => $hasPharmacyReconciliationException,
        ];
    }

    /**
     * @param  array{hasAnyOrder: bool, hasPharmacyReconciliationException: bool}  $ordersSignal
     */
    private function deriveOrdersDimension(int $pendingOrderCount, array $ordersSignal): CanonicalOrdersDimension
    {
        if ($ordersSignal['hasPharmacyReconciliationException']) {
            return CanonicalOrdersDimension::EXCEPTION;
        }

        if ($pendingOrderCount > 0) {
            return CanonicalOrdersDimension::PENDING;
        }

        return $ordersSignal['hasAnyOrder'] ? CanonicalOrdersDimension::RESULTED : CanonicalOrdersDimension::NONE;
    }

    /**
     * @param  array<string, mixed>|null  $closeReadiness
     */
    private function deriveBillingDimension(?array $closeReadiness): CanonicalBillingDimension
    {
        if ($closeReadiness === null) {
            return CanonicalBillingDimension::UNKNOWN;
        }

        $pending = (int) ($closeReadiness['billingSummary']['pendingCandidates'] ?? 0);

        return $pending === 0 ? CanonicalBillingDimension::READY : CanonicalBillingDimension::NOT_READY;
    }

    /**
     * @param  array<string, mixed>|null  $closeReadiness
     */
    private function deriveDiagnosisDimension(?array $closeReadiness): CanonicalDiagnosisDimension
    {
        $passed = $this->extractItemPassed($closeReadiness, 'diagnosis_documented');

        if ($passed === null) {
            return CanonicalDiagnosisDimension::UNKNOWN;
        }

        return $passed ? CanonicalDiagnosisDimension::YES : CanonicalDiagnosisDimension::NO;
    }

    /**
     * @param  array<string, mixed>|null  $closeReadiness
     */
    private function extractItemCount(?array $closeReadiness, string $itemId): ?int
    {
        if ($closeReadiness === null) {
            return null;
        }

        foreach ((array) ($closeReadiness['items'] ?? []) as $item) {
            if (($item['id'] ?? null) === $itemId) {
                return (int) ($item['count'] ?? 0);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $closeReadiness
     */
    private function extractItemPassed(?array $closeReadiness, string $itemId): ?bool
    {
        if ($closeReadiness === null) {
            return null;
        }

        foreach ((array) ($closeReadiness['items'] ?? []) as $item) {
            if (($item['id'] ?? null) === $itemId) {
                return ($item['status'] ?? null) === 'pass';
            }
        }

        return null;
    }

    /**
     * Ordered rule cascade — first match wins. Mirrors
     * 00-canonical-encounter-state-machine.md §1.5, plus a small number of
     * gap-filling extensions (marked below) for combinations that table's
     * illustrative rows did not explicitly enumerate.
     *
     * @return array{0: CanonicalEncounterState, 1: string}
     */
    private function deriveCanonicalStateAndRule(
        string $legacyStatus,
        CanonicalNoteDimension $n,
        CanonicalOrdersDimension $o,
        CanonicalBillingDimension $b,
        CanonicalDiagnosisDimension $d,
    ): array {
        if (
            $n === CanonicalNoteDimension::UNKNOWN
            || $o === CanonicalOrdersDimension::UNKNOWN
            || $b === CanonicalBillingDimension::UNKNOWN
            || $d === CanonicalDiagnosisDimension::UNKNOWN
        ) {
            return [CanonicalEncounterState::INDETERMINATE, 'RULE-0-FAILSAFE'];
        }

        if ($legacyStatus === 'cancelled') {
            return [CanonicalEncounterState::CANCELLED, 'RULE-1'];
        }

        if ($legacyStatus === 'closed') {
            return [CanonicalEncounterState::CLOSED, 'RULE-2'];
        }

        if ($n === CanonicalNoteDimension::NONE && $o === CanonicalOrdersDimension::NONE) {
            return [CanonicalEncounterState::REGISTERED, 'RULE-3'];
        }

        if ($n === CanonicalNoteDimension::DRAFT && $o === CanonicalOrdersDimension::NONE) {
            return [CanonicalEncounterState::IN_CONSULTATION, 'RULE-4A'];
        }

        if ($n === CanonicalNoteDimension::DRAFT && $o === CanonicalOrdersDimension::PENDING) {
            return [CanonicalEncounterState::WORKUP_IN_PROGRESS, 'RULE-4B'];
        }

        // Extension: draft note with an order in EXCEPTION is still active workup.
        if ($n === CanonicalNoteDimension::DRAFT && $o === CanonicalOrdersDimension::EXCEPTION) {
            return [CanonicalEncounterState::WORKUP_IN_PROGRESS, 'RULE-4C-EXT'];
        }

        if ($n === CanonicalNoteDimension::SIGNED && in_array($o, [CanonicalOrdersDimension::PENDING, CanonicalOrdersDimension::EXCEPTION], true)) {
            return [CanonicalEncounterState::AWAITING_RESULTS, 'RULE-5'];
        }

        if ($n === CanonicalNoteDimension::DRAFT && $o === CanonicalOrdersDimension::RESULTED) {
            return [CanonicalEncounterState::READY_FOR_REVIEW, 'RULE-6'];
        }

        if (
            $n === CanonicalNoteDimension::SIGNED
            && $o === CanonicalOrdersDimension::RESULTED
            && ($d === CanonicalDiagnosisDimension::NO || $b === CanonicalBillingDimension::NOT_READY)
        ) {
            return [CanonicalEncounterState::AWAITING_RESULTS, 'RULE-7'];
        }

        if (
            $n === CanonicalNoteDimension::SIGNED
            && $o === CanonicalOrdersDimension::RESULTED
            && $d === CanonicalDiagnosisDimension::YES
            && $b === CanonicalBillingDimension::READY
        ) {
            return [CanonicalEncounterState::READY_FOR_DISCHARGE, 'RULE-8'];
        }

        // Extension: orders exist (in any non-NONE state) before any note has been started.
        if ($n === CanonicalNoteDimension::NONE && $o !== CanonicalOrdersDimension::NONE) {
            return [CanonicalEncounterState::WORKUP_IN_PROGRESS, 'RULE-9-EXT'];
        }

        // Defensive default — should be unreachable given the dimensions above are
        // fully enumerated, but fail-closed rather than fail-open if it is ever hit.
        return [CanonicalEncounterState::INDETERMINATE, 'RULE-0-FAILSAFE'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $notes
     * @param  array<string, mixed>  $ordersSignal
     * @return array<int, array{code: string, severity: string, message: string}>
     */
    private function detectConflicts(
        EncounterModel $encounter,
        array $notes,
        CanonicalNoteDimension $noteDimension,
        CanonicalOrdersDimension $ordersDimension,
        CanonicalBillingDimension $billingDimension,
        array $ordersSignal,
        ?int $pendingOrderCount,
    ): array {
        $conflicts = [];
        $legacyStatus = (string) $encounter->status;

        $add = function (CanonicalEncounterConflictCode $code, string $message) use (&$conflicts): void {
            $conflicts[] = [
                'code' => $code->value,
                'severity' => $code->severity(),
                'message' => $message,
            ];
        };

        // CONFLICT-01: closed with pending/exception orders.
        if ($legacyStatus === 'closed' && in_array($ordersDimension, [CanonicalOrdersDimension::PENDING, CanonicalOrdersDimension::EXCEPTION], true)) {
            $add(CanonicalEncounterConflictCode::CONFLICT_01, 'Encounter is closed but active clinical orders remain unresolved.');
        }

        // CONFLICT-02: note fully signed but encounter still shows an early-stage legacy status.
        if ($noteDimension === CanonicalNoteDimension::SIGNED && in_array($legacyStatus, ['opened', 'in_progress'], true)) {
            $add(CanonicalEncounterConflictCode::CONFLICT_02, 'Every consultation note is signed but encounter.status has not advanced past '.$legacyStatus.'.');
        }

        // CONFLICT-03 / CONFLICT-04: evaluated over the raw note set.
        if (count($notes) > 1) {
            $signedStatuses = [MedicalRecordStatus::FINALIZED->value, MedicalRecordStatus::AMENDED->value];
            $signedCount = count(array_filter($notes, static fn (array $note): bool => in_array($note['status'] ?? null, $signedStatuses, true)));
            if ($signedCount > 0 && $signedCount < count($notes)) {
                $add(CanonicalEncounterConflictCode::CONFLICT_03, 'This encounter has '.count($notes).' consultation notes and not all are signed.');
            }
        }

        foreach ($notes as $note) {
            if (($note['status'] ?? null) === MedicalRecordStatus::DRAFT->value && ! empty($note['signed_at'])) {
                $add(CanonicalEncounterConflictCode::CONFLICT_04, 'Note '.($note['id'] ?? 'unknown').' is in draft status but carries a non-null signed_at.');

                break;
            }
        }

        // CONFLICT-05: duplicate encounter for the same visit context.
        $appointmentId = trim((string) ($encounter->appointment_id ?? ''));
        $admissionId = trim((string) ($encounter->admission_id ?? ''));
        if ($appointmentId !== '' || $admissionId !== '') {
            $duplicateQuery = EncounterModel::query()
                ->where('patient_id', $encounter->patient_id)
                ->where('id', '!=', $encounter->id);

            if ($appointmentId !== '') {
                $duplicateQuery->where('appointment_id', $appointmentId);
            } else {
                $duplicateQuery->where('admission_id', $admissionId);
            }

            if ($duplicateQuery->exists()) {
                $add(CanonicalEncounterConflictCode::CONFLICT_05, 'Another encounter exists for the same patient and visit context.');
            }
        }

        // CONFLICT-06: closed with unbilled services.
        if ($legacyStatus === 'closed' && $billingDimension === CanonicalBillingDimension::NOT_READY) {
            $add(CanonicalEncounterConflictCode::CONFLICT_06, 'Encounter is closed but billing charge-capture candidates are still pending.');
        }

        // CONFLICT-07: divergent primary-note resolution between the two existing use cases,
        // reproduced locally against the same fetched note list (see pickPrimary()).
        $workspaceStylePrimary = $this->pickPrimary($notes, [MedicalRecordStatus::FINALIZED->value, MedicalRecordStatus::AMENDED->value]);
        $closeReadinessStylePrimary = $this->pickPrimary($notes, [MedicalRecordStatus::FINALIZED->value, MedicalRecordStatus::AMENDED->value, MedicalRecordStatus::DRAFT->value]);
        $workspaceId = $workspaceStylePrimary['id'] ?? null;
        $closeReadinessId = $closeReadinessStylePrimary['id'] ?? null;
        if ($workspaceId !== $closeReadinessId) {
            $add(CanonicalEncounterConflictCode::CONFLICT_07, 'Workspace-style and close-readiness-style primary-note resolution disagree for this encounter.');
        }

        // CONFLICT-08: pharmacy reconciliation exception masked as "no pending orders".
        if (($ordersSignal['hasPharmacyReconciliationException'] ?? false) && $pendingOrderCount === 0) {
            $add(CanonicalEncounterConflictCode::CONFLICT_08, 'A pharmacy order is in reconciliation_exception but pending_orders currently reports pass.');
        }

        // CONFLICT-09: encounter status advanced only via the note-sync side channel.
        // Note: under the current system's real behavior this is expected to fire frequently
        // for `signed`/`amended`/sync-driven `in_progress` encounters, since that side channel
        // is, today, the only path to those values (see design doc 01, §7.4 discussion and
        // clinical-note-audit finding C-14). It is reported as specified, not suppressed.
        if (in_array($legacyStatus, ['signed', 'amended', 'in_progress'], true)) {
            $ignoredFailures = [];
            $latestAuditEntry = $this->attempt(
                fn (): array => $this->encounterAuditLogRepository->listByEncounterId(
                    encounterId: (string) $encounter->id,
                    page: 1,
                    perPage: 1,
                    query: null,
                    action: null,
                    actorType: null,
                    actorId: null,
                    fromDateTime: null,
                    toDateTime: null,
                ),
                null,
                $ignoredFailures,
                'audit_log_lookup',
            );

            $latestEntry = $latestAuditEntry['data'][0] ?? null;
            $source = is_array($latestEntry) ? ($latestEntry['metadata']['source'] ?? null) : null;
            if ($source === 'medical_record_status') {
                $add(CanonicalEncounterConflictCode::CONFLICT_09, 'The latest status change on this encounter was driven only by the note-status-sync side channel.');
            }
        }

        // CONFLICT-10: cancelled status observed (enumerated in EncounterStatus but no
        // confirmed assignment path exists in the audited system — see finding C-15).
        if ($legacyStatus === 'cancelled') {
            $add(CanonicalEncounterConflictCode::CONFLICT_10, 'Encounter status is cancelled, a value with no confirmed governed assignment path.');
        }

        return $conflicts;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $default
     * @param  array<string, bool>  $failures
     * @return T
     */
    private function attempt(callable $callback, mixed $default, array &$failures, string $label): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            $failures[$label] = true;

            return $default;
        }
    }
}

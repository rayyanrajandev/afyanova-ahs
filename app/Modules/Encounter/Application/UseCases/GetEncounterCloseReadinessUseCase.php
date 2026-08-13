<?php

namespace App\Modules\Encounter\Application\UseCases;

use App\Modules\Billing\Application\UseCases\ListBillingChargeCaptureCandidatesUseCase;
use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\Encounter\Application\Services\PrimaryMedicalRecordResolverService;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Laboratory\Domain\ValueObjects\LaboratoryOrderStatus;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordNoteType;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;
use App\Modules\Pharmacy\Domain\ValueObjects\PharmacyOrderStatus;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Radiology\Domain\ValueObjects\RadiologyOrderStatus;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\ClinicalProcedure\Domain\ValueObjects\ClinicalProcedureOrderStatus;
use App\Modules\ClinicalProcedure\Infrastructure\Models\ClinicalProcedureOrderModel;
use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureStatus;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use App\Support\ClinicalOrders\ClinicalOrderEntryState;

class GetEncounterCloseReadinessUseCase
{
    // Public: reused by GetEncounterWorkspaceUseCase for C-8's pending-first
    // order-panel sort (reports/clinical-note-audit/15-critical-system-integrity-review.md).
    // Deliberately a single source of truth rather than a second copy — a
    // duplicated list is exactly what let C-11 drift out of sync with reality.
    // Methods (not consts) because PHP constant expressions can't call another
    // class's static method — this derives from each order type's own status
    // enum instead of hand-copying its terminal states here.
    public static function labTerminalStatuses(): array
    {
        return LaboratoryOrderStatus::terminalValues();
    }

    // C-11 (reports/clinical-note-audit/15-critical-system-integrity-review.md):
    // reconciliation_exception is an unresolved-problem state (a flagged
    // medication-reconciliation discrepancy), not a safe end-state — it must
    // not be grouped with dispensed/cancelled/reconciliation_completed here,
    // or an unresolved medication-safety flag silently stops contributing to
    // the pending-orders close-readiness warning the moment it's raised.
    //
    // 'reconciliation_completed' is not a real value the pharmacy_orders.status
    // column ever receives in production — PharmacyOrderStatus has no such
    // case, since reconciliation state lives in a separate reconciliation_status
    // column. It's kept here only because
    // EncounterCloseReadinessPharmacyReconciliationTest asserts against a
    // synthetic row that writes this literal into `status` directly.
    public static function pharmacyTerminalStatuses(): array
    {
        return [...PharmacyOrderStatus::terminalValues(), 'reconciliation_completed'];
    }

    public static function radiologyTerminalStatuses(): array
    {
        return RadiologyOrderStatus::terminalValues();
    }

    public static function theatreTerminalStatuses(): array
    {
        return TheatreProcedureStatus::terminalValues();
    }

    public static function clinicalProcedureTerminalStatuses(): array
    {
        return ClinicalProcedureOrderStatus::terminalValues();
    }

    // C-5 (reports/clinical-note-audit/15-critical-system-integrity-review.md),
    // acknowledgement-quality fix (decided 2026-07-08): cap on how many
    // outstanding items are itemized in the close-readiness response. This is
    // a display bound for the close-checklist dialog, not a change to the
    // pending count itself — canConfirm/canClose still key off the count.
    private const ITEM_DETAIL_LIMIT = 20;

    public function __construct(
        private readonly EncounterResolverService $encounterResolverService,
        private readonly MedicalRecordRepositoryInterface $medicalRecordRepository,
        private readonly PrimaryMedicalRecordResolverService $primaryMedicalRecordResolverService,
        private readonly ListBillingChargeCaptureCandidatesUseCase $chargeCaptureCandidatesUseCase,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(string $encounterId, ?string $dispositionOverride = null): ?array
    {
        $encounter = $this->encounterResolverService->findById($encounterId);
        if ($encounter === null) {
            return null;
        }

        $encounterArray = $encounter->toArray();
        $patientId = trim((string) ($encounterArray['patient_id'] ?? ''));
        $primaryMedicalRecord = $this->primaryMedicalRecordResolverService->resolve($encounterId, $patientId);
        $noteStatus = strtolower(trim((string) ($primaryMedicalRecord['status'] ?? '')));
        $hasSignedNote = in_array($noteStatus, [
            MedicalRecordStatus::FINALIZED->value,
            MedicalRecordStatus::AMENDED->value,
        ], true);

        // C-2 (reports/clinical-note-audit/15-critical-system-integrity-review.md):
        // PrimaryMedicalRecordResolverService::resolve() returns the first
        // FINALIZED/AMENDED/DRAFT match it finds, in that priority order — so a
        // single signed note used to satisfy this item even if a *different*
        // consultation note on the same encounter (an addendum, a co-signer's
        // note, a corrected re-entry) was still an untouched draft. An
        // encounter must have zero unsigned consultation-note drafts to
        // close, not merely one signed one.
        $hasUnsignedNote = $this->hasUnsignedConsultationNote($encounterId, $patientId);
        $noteSigned = $hasSignedNote && ! $hasUnsignedNote;

        $diagnosisCode = trim((string) ($primaryMedicalRecord['diagnosis_code'] ?? ''));
        $assessment = trim((string) ($primaryMedicalRecord['assessment'] ?? ''));
        $diagnosisDocumented = $diagnosisCode !== '' || $assessment !== '';

        $pendingOrderDetails = $this->pendingOrderDetails($encounterId);
        $pendingOrderCount = $this->countPendingOrders($encounterId);
        $billingSummary = $this->resolveBillingSummary($encounter, $patientId);

        // dispositionOverride lets a close attempt (which submits disposition
        // in the same request) be judged against what's about to be saved,
        // not stale persisted state — see EncounterLifecycleService::close().
        $dispositionValue = $dispositionOverride !== null
            ? trim($dispositionOverride)
            : trim((string) ($encounterArray['disposition'] ?? ''));
        $dispositionDocumented = $dispositionValue !== '';

        $items = [
            $this->buildItem(
                id: 'note_signed',
                label: 'Consultation note signed',
                severity: 'block',
                passed: $noteSigned,
                message: match (true) {
                    $noteSigned => 'The consultation note is finalized or amended.',
                    $hasUnsignedNote => 'A draft consultation note for this encounter is still unsigned — finalize or amend it before closing.',
                    default => 'Finalize or amend the consultation note before closing this encounter.',
                },
            ),
            $this->buildItem(
                id: 'diagnosis_documented',
                label: 'Diagnosis documented',
                severity: 'warn',
                passed: $diagnosisDocumented,
                message: $diagnosisDocumented
                    ? 'Assessment narrative or ICD-10 diagnosis code is present.'
                    : 'Add an assessment or ICD-10 diagnosis code before close-out.',
            ),
            $this->buildItem(
                id: 'pending_orders',
                label: 'Pending clinical orders',
                severity: 'warn',
                passed: $pendingOrderCount === 0,
                count: $pendingOrderCount,
                message: $pendingOrderCount === 0
                    ? 'No active pending orders remain on this encounter.'
                    : sprintf(
                        '%d active order%s still need completion or cancellation.',
                        $pendingOrderCount,
                        $pendingOrderCount === 1 ? '' : 's',
                    ),
                // C-5 acknowledgement-quality fix: the checklist dialog used to
                // show only this count — a clinician acknowledging "3" had no
                // way to see which 3 orders they were leaving outstanding.
                details: $pendingOrderDetails,
            ),
            $this->buildItem(
                id: 'unbilled_services',
                label: 'Charge capture',
                severity: 'warn',
                passed: (int) ($billingSummary['pendingCandidates'] ?? 0) === 0,
                count: (int) ($billingSummary['pendingCandidates'] ?? 0),
                message: (int) ($billingSummary['pendingCandidates'] ?? 0) === 0
                    ? 'No uninvoiced completed services were detected for this encounter.'
                    : sprintf(
                        '%d completed service%s still need billing capture.',
                        (int) $billingSummary['pendingCandidates'],
                        (int) $billingSummary['pendingCandidates'] === 1 ? '' : 's',
                    ),
                details: is_array($billingSummary['pendingCandidateDetails'] ?? null)
                    ? $billingSummary['pendingCandidateDetails']
                    : [],
            ),
            $this->buildItem(
                id: 'disposition_documented',
                label: 'Disposition recorded',
                severity: 'block',
                passed: $dispositionDocumented,
                message: $dispositionDocumented
                    ? 'Encounter disposition has been recorded.'
                    : 'Record how this encounter concluded (e.g. discharged, admitted, transferred, referred) before closing.',
            ),
        ];

        $blockingCount = count(array_filter(
            $items,
            static fn (array $item): bool => ($item['severity'] ?? '') === 'block' && ($item['status'] ?? '') === 'fail',
        ));
        $warningCount = count(array_filter(
            $items,
            static fn (array $item): bool => ($item['severity'] ?? '') === 'warn' && ($item['status'] ?? '') === 'fail',
        ));

        return [
            'canClose' => $blockingCount === 0,
            'requiresAcknowledgement' => $blockingCount === 0 && $warningCount > 0,
            'blockingCount' => $blockingCount,
            'warningCount' => $warningCount,
            'items' => $items,
            // Unchanged shape — pendingCandidateDetails lives on the
            // unbilled_services item's `details` instead of duplicating it here.
            'billingSummary' => [
                'pendingCandidates' => (int) ($billingSummary['pendingCandidates'] ?? 0),
                'alreadyInvoiced' => (int) ($billingSummary['alreadyInvoiced'] ?? 0),
                'totalCandidates' => (int) ($billingSummary['totalCandidates'] ?? 0),
                'currencyCode' => $billingSummary['currencyCode'] ?? null,
            ],
        ];
    }

    /**
     * Whether any consultation note tied to this encounter is still a draft —
     * distinct from resolvePrimaryMedicalRecord()'s "pick one representative
     * note" resolution above. See the C-2 note on $hasUnsignedNote in execute().
     */
    private function hasUnsignedConsultationNote(string $encounterId, string $patientId): bool
    {
        if ($patientId === '') {
            return false;
        }

        $search = $this->medicalRecordRepository->search(
            query: null,
            patientId: $patientId,
            encounterId: $encounterId,
            appointmentId: null,
            appointmentReferralId: null,
            admissionId: null,
            theatreProcedureId: null,
            authorUserId: null,
            status: MedicalRecordStatus::DRAFT->value,
            recordType: MedicalRecordNoteType::CONSULTATION_NOTE->value,
            fromDateTime: null,
            toDateTime: null,
            page: 1,
            perPage: 1,
            sortBy: 'updated_at',
            sortDirection: 'desc',
        );

        return $search['data'] !== [];
    }

    private function countPendingOrders(string $encounterId): int
    {
        return $this->countPendingLaboratoryOrders($encounterId)
            + $this->countPendingPharmacyOrders($encounterId)
            + $this->countPendingRadiologyOrders($encounterId)
            + $this->countPendingTheatreProcedures($encounterId)
            + $this->countPendingClinicalProcedureOrders($encounterId);
    }

    /**
     * C-5 acknowledgement-quality fix: the pending-orders count alone doesn't
     * tell a clinician *what* they're leaving outstanding. Bounded by
     * ITEM_DETAIL_LIMIT per type — a display list for the close-checklist
     * dialog, not a replacement for the authoritative count above.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pendingOrderDetails(string $encounterId): array
    {
        $lab = LaboratoryOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->whereNull('entered_in_error_at')
            ->whereNotIn('status', self::labTerminalStatuses())
            ->orderByDesc('ordered_at')
            ->limit(self::ITEM_DETAIL_LIMIT)
            ->get(['id', 'test_name', 'ordered_at'])
            ->map(static fn (LaboratoryOrderModel $order): array => [
                'id' => $order->id,
                'type' => 'laboratory',
                'label' => $order->test_name,
                'orderedAt' => $order->ordered_at?->toIso8601String(),
            ]);

        $pharmacy = PharmacyOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->whereNull('entered_in_error_at')
            ->whereNotIn('status', self::pharmacyTerminalStatuses())
            ->orderByDesc('ordered_at')
            ->limit(self::ITEM_DETAIL_LIMIT)
            ->get(['id', 'medication_name', 'ordered_at'])
            ->map(static fn (PharmacyOrderModel $order): array => [
                'id' => $order->id,
                'type' => 'pharmacy',
                'label' => $order->medication_name,
                'orderedAt' => $order->ordered_at?->toIso8601String(),
            ]);

        $radiology = RadiologyOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->whereNull('entered_in_error_at')
            ->whereNotIn('status', self::radiologyTerminalStatuses())
            ->orderByDesc('ordered_at')
            ->limit(self::ITEM_DETAIL_LIMIT)
            ->get(['id', 'study_description', 'ordered_at'])
            ->map(static fn (RadiologyOrderModel $order): array => [
                'id' => $order->id,
                'type' => 'radiology',
                'label' => $order->study_description,
                'orderedAt' => $order->ordered_at?->toIso8601String(),
            ]);

        $theatre = TheatreProcedureModel::query()
            ->where('encounter_id', $encounterId)
            ->whereNull('entered_in_error_at')
            ->whereNotIn('status', self::theatreTerminalStatuses())
            ->orderByDesc('scheduled_at')
            ->limit(self::ITEM_DETAIL_LIMIT)
            ->get(['id', 'procedure_name', 'scheduled_at'])
            ->map(static fn (TheatreProcedureModel $procedure): array => [
                'id' => $procedure->id,
                'type' => 'theatre',
                'label' => $procedure->procedure_name,
                'orderedAt' => $procedure->scheduled_at?->toIso8601String(),
            ]);

        $clinicalProcedure = ClinicalProcedureOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->whereNull('entered_in_error_at')
            ->whereNotIn('status', self::clinicalProcedureTerminalStatuses())
            ->orderByDesc('ordered_at')
            ->limit(self::ITEM_DETAIL_LIMIT)
            ->get(['id', 'procedure_description', 'ordered_at'])
            ->map(static fn (ClinicalProcedureOrderModel $order): array => [
                'id' => $order->id,
                'type' => 'clinical_procedure',
                'label' => $order->procedure_description,
                'orderedAt' => $order->ordered_at?->toIso8601String(),
            ]);

        return $lab->concat($pharmacy)->concat($radiology)->concat($theatre)->concat($clinicalProcedure)
            ->take(self::ITEM_DETAIL_LIMIT)
            ->values()
            ->all();
    }

    private function countPendingLaboratoryOrders(string $encounterId): int
    {
        return LaboratoryOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->whereNull('entered_in_error_at')
            ->whereNotIn('status', self::labTerminalStatuses())
            ->count();
    }

    private function countPendingPharmacyOrders(string $encounterId): int
    {
        return PharmacyOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->whereNull('entered_in_error_at')
            ->whereNotIn('status', self::pharmacyTerminalStatuses())
            ->count();
    }

    private function countPendingRadiologyOrders(string $encounterId): int
    {
        return RadiologyOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->whereNull('entered_in_error_at')
            ->whereNotIn('status', self::radiologyTerminalStatuses())
            ->count();
    }

    private function countPendingTheatreProcedures(string $encounterId): int
    {
        return TheatreProcedureModel::query()
            ->where('encounter_id', $encounterId)
            ->whereNull('entered_in_error_at')
            ->whereNotIn('status', self::theatreTerminalStatuses())
            ->count();
    }

    private function countPendingClinicalProcedureOrders(string $encounterId): int
    {
        return ClinicalProcedureOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->whereNull('entered_in_error_at')
            ->whereNotIn('status', self::clinicalProcedureTerminalStatuses())
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveBillingSummary(EncounterModel $encounter, string $patientId): array
    {
        if ($patientId === '') {
            return [
                'pendingCandidates' => 0,
                'alreadyInvoiced' => 0,
                'totalCandidates' => 0,
                'currencyCode' => null,
                'pendingCandidateDetails' => [],
            ];
        }

        $result = $this->chargeCaptureCandidatesUseCase->execute([
            'patientId' => $patientId,
            'encounterId' => (string) $encounter->id,
            'appointmentId' => $encounter->appointment_id,
            'admissionId' => $encounter->admission_id,
            'includeInvoiced' => false,
            'limit' => 200,
        ]);

        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        // includeInvoiced: false above means $result['data'] already contains
        // only the pending (not-yet-invoiced) candidates — no extra query, no
        // extra filtering, just surfacing what was already fetched instead of
        // discarding everything but the count (C-5 acknowledgement-quality fix).
        $candidates = is_array($result['data'] ?? null) ? $result['data'] : [];

        return [
            'pendingCandidates' => (int) ($meta['pending'] ?? 0),
            'alreadyInvoiced' => (int) ($meta['alreadyInvoiced'] ?? 0),
            'totalCandidates' => (int) ($meta['total'] ?? 0),
            'currencyCode' => $meta['currencyCode'] ?? null,
            'pendingCandidateDetails' => array_map(
                static fn (array $candidate): array => [
                    'id' => $candidate['id'] ?? null,
                    'label' => $candidate['serviceName'] ?? $candidate['sourceNumber'] ?? 'Uninvoiced service',
                    'meta' => isset($candidate['lineTotal'], $candidate['currencyCode'])
                        ? sprintf('%s %s', $candidate['currencyCode'], number_format((float) $candidate['lineTotal'], 2))
                        : null,
                ],
                array_slice($candidates, 0, self::ITEM_DETAIL_LIMIT),
            ),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     * @return array<string, mixed>
     */
    private function buildItem(
        string $id,
        string $label,
        string $severity,
        bool $passed,
        string $message,
        ?int $count = null,
        array $details = [],
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'severity' => $severity,
            'status' => $passed ? 'pass' : 'fail',
            'message' => $message,
            'count' => $count,
            'details' => $details,
        ];
    }
}

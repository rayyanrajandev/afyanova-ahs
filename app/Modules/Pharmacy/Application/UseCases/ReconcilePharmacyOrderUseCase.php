<?php

namespace App\Modules\Pharmacy\Application\UseCases;

use App\Modules\Patient\Application\UseCases\CreatePatientMedicationProfileUseCase;
use App\Modules\Patient\Application\UseCases\UpdatePatientMedicationProfileUseCase;
use App\Modules\Patient\Domain\Repositories\PatientMedicationProfileRepositoryInterface;
use App\Modules\Pharmacy\Application\Exceptions\PharmacyOrderReconciliationNotAllowedException;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderAuditLogRepositoryInterface;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;
use App\Modules\Pharmacy\Domain\ValueObjects\PharmacyOrderStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use Illuminate\Validation\ValidationException;

class ReconcilePharmacyOrderUseCase
{
    private const COMPLETED_DECISIONS = [
        'add_to_current_list',
        'continue_on_current_list',
        'short_course_only',
        'stop_from_current_list',
    ];

    public function __construct(
        private readonly PharmacyOrderRepositoryInterface $pharmacyOrderRepository,
        private readonly PharmacyOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly PatientMedicationProfileRepositoryInterface $patientMedicationProfileRepository,
        private readonly CreatePatientMedicationProfileUseCase $createPatientMedicationProfileUseCase,
        private readonly UpdatePatientMedicationProfileUseCase $updatePatientMedicationProfileUseCase,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->pharmacyOrderRepository->findById($id);
        if (! $existing) {
            return null;
        }

        ClinicalOrderLifecycle::assertActiveForWorkflow($existing, 'pharmacy order');

        if (($existing['status'] ?? null) !== PharmacyOrderStatus::DISPENSED->value) {
            throw new PharmacyOrderReconciliationNotAllowedException('Only dispensed pharmacy orders can be reconciled.');
        }

        if (empty($existing['verified_at'])) {
            throw new PharmacyOrderReconciliationNotAllowedException('Dispense verification is required before reconciliation.');
        }

        $reconciliationStatus = (string) $payload['reconciliation_status'];
        $reconciliationNote = $this->nullableString($payload['reconciliation_note'] ?? null);
        $reconciliationDecision = $this->normalizeReconciliationDecision(
            $reconciliationStatus,
            $payload['reconciliation_decision'] ?? null,
        );
        $this->assertDecisionRequirements($reconciliationStatus, $reconciliationDecision);

        $workflowStatusSatisfied = ($existing['status'] ?? null) === PharmacyOrderStatus::DISPENSED->value;
        $verificationPresent = ! empty($existing['verified_at'] ?? null);
        $reconciliationNoteRequired = $reconciliationStatus === 'exception';
        $reconciliationDecisionRequired = $reconciliationStatus === 'completed';
        $medicationListSync = $this->synchronizeCurrentMedicationList(
            order: $existing,
            reconciliationStatus: $reconciliationStatus,
            reconciliationDecision: $reconciliationDecision,
            reconciliationNote: $reconciliationNote,
            actorId: $actorId,
        );
        $updatePayload = [
            'reconciliation_status' => $reconciliationStatus,
            'reconciliation_decision' => $reconciliationDecision,
            'reconciliation_note' => $reconciliationNote,
            'reconciled_at' => $reconciliationStatus === 'pending' ? null : now(),
            'reconciled_by_user_id' => $reconciliationStatus === 'pending' ? null : $actorId,
        ];

        $updated = $this->pharmacyOrderRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            pharmacyOrderId: $id,
            action: 'pharmacy-order.reconciliation.updated',
            actorId: $actorId,
            changes: [
                'reconciliation_status' => [
                    'before' => $existing['reconciliation_status'] ?? null,
                    'after' => $updated['reconciliation_status'] ?? null,
                ],
                'reconciliation_decision' => [
                    'before' => $existing['reconciliation_decision'] ?? null,
                    'after' => $updated['reconciliation_decision'] ?? null,
                ],
                'reconciliation_note' => [
                    'before' => $existing['reconciliation_note'] ?? null,
                    'after' => $updated['reconciliation_note'] ?? null,
                ],
                'reconciled_at' => [
                    'before' => $existing['reconciled_at'] ?? null,
                    'after' => $updated['reconciled_at'] ?? null,
                ],
                'reconciled_by_user_id' => [
                    'before' => $existing['reconciled_by_user_id'] ?? null,
                    'after' => $updated['reconciled_by_user_id'] ?? null,
                ],
            ],
            metadata: [
                'transition' => [
                    'from' => $existing['reconciliation_status'] ?? null,
                    'to' => $updated['reconciliation_status'] ?? null,
                ],
                'workflow_status_required' => PharmacyOrderStatus::DISPENSED->value,
                'workflow_status_satisfied' => $workflowStatusSatisfied,
                'verification_required' => true,
                'verification_present' => $verificationPresent,
                'reconciliation_note_required' => $reconciliationNoteRequired,
                'reconciliation_note_provided' => trim((string) ($updated['reconciliation_note'] ?? '')) !== '',
                'reconciliation_decision_required' => $reconciliationDecisionRequired,
                'reconciliation_decision_provided' => trim((string) ($updated['reconciliation_decision'] ?? '')) !== '',
                'reconciled_timestamp_required' => $reconciliationStatus !== 'pending',
                'reconciled_timestamp_provided' => ! empty($updated['reconciled_at'] ?? null),
                'reconciled_by_required' => $reconciliationStatus !== 'pending',
                'reconciled_by_provided' => ! empty($updated['reconciled_by_user_id'] ?? null),
                'medication_list_sync' => $medicationListSync,
            ],
        );

        return $updated;
    }

    private function normalizeReconciliationDecision(
        string $reconciliationStatus,
        mixed $reconciliationDecision,
    ): ?string {
        $normalizedDecision = $this->nullableString($reconciliationDecision);

        if ($reconciliationStatus === 'pending') {
            return 'review_later';
        }

        if ($reconciliationStatus === 'exception') {
            return null;
        }

        return $normalizedDecision;
    }

    private function assertDecisionRequirements(
        string $reconciliationStatus,
        ?string $reconciliationDecision,
    ): void {
        if (
            $reconciliationStatus === 'completed' &&
            ! in_array($reconciliationDecision, self::COMPLETED_DECISIONS, true)
        ) {
            throw ValidationException::withMessages([
                'reconciliationDecision' => [
                    'Select the final medication reconciliation outcome.',
                ],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function synchronizeCurrentMedicationList(
        array $order,
        string $reconciliationStatus,
        ?string $reconciliationDecision,
        ?string $reconciliationNote,
        ?int $actorId,
    ): array {
        if ($reconciliationStatus !== 'completed' || $reconciliationDecision === null) {
            return [
                'applied' => false,
                'mode' => 'none',
                'decision' => $reconciliationDecision,
                'profile_ids' => [],
            ];
        }

        $patientId = trim((string) ($order['patient_id'] ?? ''));
        if ($patientId === '') {
            return [
                'applied' => false,
                'mode' => 'missing_patient',
                'decision' => $reconciliationDecision,
                'profile_ids' => [],
            ];
        }

        if ($reconciliationDecision === 'short_course_only') {
            return [
                'applied' => false,
                'mode' => 'short_course_only',
                'decision' => $reconciliationDecision,
                'profile_ids' => [],
            ];
        }

        $matches = $this->patientMedicationProfileRepository->findMatchingActiveByPatientId(
            patientId: $patientId,
            medicationCode: $this->nullableString($order['medication_code'] ?? null),
            medicationName: $this->nullableString($order['medication_name'] ?? null),
            limit: 10,
        );

        return match ($reconciliationDecision) {
            'add_to_current_list' => $this->applyAddToCurrentMedicationList(
                order: $order,
                patientId: $patientId,
                matches: $matches,
                reconciliationNote: $reconciliationNote,
                actorId: $actorId,
            ),
            'continue_on_current_list' => $this->applyContinueOnCurrentMedicationList(
                order: $order,
                patientId: $patientId,
                matches: $matches,
                reconciliationNote: $reconciliationNote,
                actorId: $actorId,
            ),
            'stop_from_current_list' => $this->applyStopFromCurrentMedicationList(
                order: $order,
                patientId: $patientId,
                matches: $matches,
                reconciliationNote: $reconciliationNote,
                actorId: $actorId,
            ),
            default => [
                'applied' => false,
                'mode' => 'unhandled',
                'decision' => $reconciliationDecision,
                'profile_ids' => [],
            ],
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<string, mixed>
     */
    private function applyAddToCurrentMedicationList(
        array $order,
        string $patientId,
        array $matches,
        ?string $reconciliationNote,
        ?int $actorId,
    ): array {
        if ($matches !== []) {
            return array_merge(
                $this->applyContinueOnCurrentMedicationList(
                    order: $order,
                    patientId: $patientId,
                    matches: $matches,
                    reconciliationNote: $reconciliationNote,
                    actorId: $actorId,
                ),
                ['mode' => 'matched_existing_entry'],
            );
        }

        $created = $this->createPatientMedicationProfileUseCase->execute(
            patientId: $patientId,
            payload: [
                'medication_code' => $this->nullableString($order['medication_code'] ?? null),
                'medication_name' => $this->nullableString($order['medication_name'] ?? null) ?? 'Medication',
                'dose' => $this->nullableString($order['dosage_instruction'] ?? null),
                'source' => 'manual_entry',
                'status' => 'active',
                'started_at' => now(),
                'last_reconciled_at' => now(),
                'reconciliation_note' => $this->buildMedicationListReconciliationNote(
                    order: $order,
                    actionLabel: 'Added to the current medication list during pharmacy reconciliation.',
                    reconciliationNote: $reconciliationNote,
                ),
                'notes' => $this->buildMedicationListLinkNote($order, 'Created from pharmacy reconciliation.'),
            ],
            actorId: $actorId,
        );

        return [
            'applied' => $created !== null,
            'mode' => 'created_new_entry',
            'decision' => 'add_to_current_list',
            'profile_ids' => $created ? [$created['id']] : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<string, mixed>
     */
    private function applyContinueOnCurrentMedicationList(
        array $order,
        string $patientId,
        array $matches,
        ?string $reconciliationNote,
        ?int $actorId,
    ): array {
        if ($matches === []) {
            return array_merge(
                $this->applyAddToCurrentMedicationList(
                    order: $order,
                    patientId: $patientId,
                    matches: $matches,
                    reconciliationNote: $reconciliationNote,
                    actorId: $actorId,
                ),
                ['mode' => 'created_missing_entry'],
            );
        }

        $profileIds = [];
        foreach ($matches as $match) {
            $updated = $this->updatePatientMedicationProfileUseCase->execute(
                patientId: $patientId,
                medicationId: (string) $match['id'],
                payload: [
                    'status' => 'active',
                    'dose' => $this->nullableString($match['dose'] ?? null)
                        ?? $this->nullableString($order['dosage_instruction'] ?? null),
                    'last_reconciled_at' => now(),
                    'reconciliation_note' => $this->buildMedicationListReconciliationNote(
                        order: $order,
                        actionLabel: 'Confirmed as continuing on the current medication list during pharmacy reconciliation.',
                        reconciliationNote: $reconciliationNote,
                    ),
                    'notes' => $this->appendText(
                        $this->nullableString($match['notes'] ?? null),
                        $this->buildMedicationListLinkNote($order, 'Confirmed during pharmacy reconciliation.'),
                    ),
                ],
                actorId: $actorId,
            );

            if ($updated !== null) {
                $profileIds[] = $updated['id'];
            }
        }

        return [
            'applied' => $profileIds !== [],
            'mode' => 'updated_existing_entries',
            'decision' => 'continue_on_current_list',
            'profile_ids' => $profileIds,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<string, mixed>
     */
    private function applyStopFromCurrentMedicationList(
        array $order,
        string $patientId,
        array $matches,
        ?string $reconciliationNote,
        ?int $actorId,
    ): array {
        if ($matches === []) {
            return [
                'applied' => false,
                'mode' => 'no_active_entry_to_stop',
                'decision' => 'stop_from_current_list',
                'profile_ids' => [],
            ];
        }

        $profileIds = [];
        foreach ($matches as $match) {
            $updated = $this->updatePatientMedicationProfileUseCase->execute(
                patientId: $patientId,
                medicationId: (string) $match['id'],
                payload: [
                    'status' => 'stopped',
                    'stopped_at' => now(),
                    'last_reconciled_at' => now(),
                    'reconciliation_note' => $this->buildMedicationListReconciliationNote(
                        order: $order,
                        actionLabel: 'Marked as stopped from the current medication list during pharmacy reconciliation.',
                        reconciliationNote: $reconciliationNote,
                    ),
                    'notes' => $this->appendText(
                        $this->nullableString($match['notes'] ?? null),
                        $this->buildMedicationListLinkNote($order, 'Stopped during pharmacy reconciliation.'),
                    ),
                ],
                actorId: $actorId,
            );

            if ($updated !== null) {
                $profileIds[] = $updated['id'];
            }
        }

        return [
            'applied' => $profileIds !== [],
            'mode' => 'stopped_existing_entries',
            'decision' => 'stop_from_current_list',
            'profile_ids' => $profileIds,
        ];
    }

    private function buildMedicationListReconciliationNote(
        array $order,
        string $actionLabel,
        ?string $reconciliationNote,
    ): string {
        $lines = [
            $actionLabel,
            sprintf(
                'Linked pharmacy order: %s.',
                $this->nullableString($order['order_number'] ?? null)
                    ?? ('order '.$this->nullableString($order['id'] ?? null)),
            ),
        ];

        if ($reconciliationNote !== null) {
            $lines[] = $reconciliationNote;
        }

        return implode("\n", array_filter($lines));
    }

    private function buildMedicationListLinkNote(array $order, string $prefix): string
    {
        $orderNumber = $this->nullableString($order['order_number'] ?? null)
            ?? ('order '.$this->nullableString($order['id'] ?? null));

        return trim($prefix.' Linked pharmacy order: '.$orderNumber.'.');
    }

    private function appendText(?string $existing, string $addition): string
    {
        $normalizedExisting = trim((string) $existing);
        if ($normalizedExisting === '') {
            return $addition;
        }

        if (str_contains($normalizedExisting, $addition)) {
            return $normalizedExisting;
        }

        return $normalizedExisting."\n".$addition;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}

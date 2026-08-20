<?php

namespace App\Modules\Pharmacy\Application\UseCases;

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\InventoryProcurement\Application\Services\DepartmentStockService;
use App\Modules\InventoryProcurement\Application\Services\InventoryBatchStockService;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\Pharmacy\Application\Exceptions\PharmacyOrderStatusUpdateNotAllowedException;
use App\Modules\Pharmacy\Application\Services\RecordPharmacyFlowTransitionService;
use App\Modules\Pharmacy\Application\Support\ApprovedMedicineGovernance;
use App\Modules\Pharmacy\Domain\Events\PharmacyOrderDispensed;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderAuditLogRepositoryInterface;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;
use App\Modules\Pharmacy\Domain\ValueObjects\PharmacyOrderStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Revenue\Application\Services\PrepaidGatePolicy;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use Illuminate\Support\Facades\DB;

class UpdatePharmacyOrderStatusUseCase
{
    /**
     * Staff-facing write paths for the flow log, keyed by the status moved *to*.
     *
     * Cancellation is absent on purpose: it withdraws work rather than advancing
     * it, and the flow vocabulary has no word for that — the same choice the
     * laboratory and radiology paths make.
     */
    private const FLOW_SOURCES_BY_STATUS = [
        PharmacyOrderStatus::IN_PREPARATION->value => 'pharmacy.preparation_started',
        PharmacyOrderStatus::PARTIALLY_DISPENSED->value => 'pharmacy.partially_dispensed',
        PharmacyOrderStatus::DISPENSED->value => 'pharmacy.dispensed',
    ];

    public function __construct(
        private readonly PharmacyOrderRepositoryInterface $pharmacyOrderRepository,
        private readonly PharmacyOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly InventoryItemRepositoryInterface $inventoryItemRepository,
        private readonly InventoryBatchStockService $inventoryBatchStockService,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly DepartmentStockService $departmentStockService,
        private readonly DepartmentRepositoryInterface $departmentRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly RecordPharmacyFlowTransitionService $recordFlowTransition,
        private readonly PrepaidGatePolicy $prepaidGate,
    ) {}

    public function execute(
        string $id,
        string $status,
        ?string $reason,
        ?float $quantityDispensed,
        ?string $dispensedUnit,
        ?string $dispensingNotes,
        ?string $batchId = null,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->pharmacyOrderRepository->findById($id);
        if (! $existing) {
            return null;
        }

        ClinicalOrderLifecycle::assertActiveForWorkflow($existing, 'pharmacy order');

        // Prepaid gate. The statuses below are this module's own
        // declaration of what "providing the service" means; the rule
        // itself lives in PrepaidGatePolicy.
        $this->prepaidGate->assertAuthorized(
            kind: ChargeSourceKind::PHARMACY_ORDER,
            orderId: $id,
            targetStatus: $status,
            deliveryStatuses: [
                PharmacyOrderStatus::IN_PREPARATION->value,
                PharmacyOrderStatus::PARTIALLY_DISPENSED->value,
                PharmacyOrderStatus::DISPENSED->value,
            ],
            refusalMessage: 'Prescription cannot be prepared or dispensed before payment has been verified.',
        );

        $quantityDispensedInputProvided = $quantityDispensed !== null;
        $dispensingNotesInputProvided = trim((string) ($dispensingNotes ?? '')) !== '';
        $currentStatus = (string) ($existing['status'] ?? '');
        $quantityPrescribed = round((float) ($existing['quantity_prescribed'] ?? 0), 2);
        $previousQuantityDispensed = round((float) ($existing['quantity_dispensed'] ?? 0), 2);

        $this->assertWorkflowTransitionAllowed(
            currentStatus: $currentStatus,
            requestedStatus: $status,
        );
        $this->assertPolicyWorkflowReady(
            order: $existing,
            requestedStatus: $status,
        );

        $payload = [
            'status' => $status,
            'status_reason' => $reason,
        ];

        if ($quantityDispensed !== null) {
            $payload['quantity_dispensed'] = round(max($quantityDispensed, 0), 2);
        }

        $resolvedDispensedUnit = $this->resolveDispensedUnit($existing, $dispensedUnit);
        if ($resolvedDispensedUnit !== null) {
            $payload['dispensed_unit'] = $resolvedDispensedUnit;
        }

        if ($dispensingNotes !== null) {
            $payload['dispensing_notes'] = $dispensingNotes;
        }

        $this->assertRequestedQuantityValid(
            currentStatus: $currentStatus,
            requestedStatus: $status,
            quantityPrescribed: $quantityPrescribed,
            previousQuantityDispensed: $previousQuantityDispensed,
            quantityDispensed: $quantityDispensed,
            quantityDispensedInputProvided: $quantityDispensedInputProvided,
        );

        if ($status === PharmacyOrderStatus::DISPENSED->value) {
            if (! array_key_exists('quantity_dispensed', $payload)) {
                $payload['quantity_dispensed'] = $quantityPrescribed;
            }
            $payload['dispensed_at'] = now();
        }

        // Who released the medicine, so verification can insist on someone
        // else. Recorded on the first release and left alone afterwards: a
        // partial fill is a release, and the pharmacist who began handing the
        // medicine over is the one the second pair of eyes is checking.
        if (in_array(
            $status,
            [
                PharmacyOrderStatus::PARTIALLY_DISPENSED->value,
                PharmacyOrderStatus::DISPENSED->value,
            ],
            true,
        ) && ($existing['dispensed_by_user_id'] ?? null) === null) {
            $payload['dispensed_by_user_id'] = $actorId;
        }

        $stockIssueQuantity = $this->resolveStockIssueQuantity(
            existing: $existing,
            payload: $payload,
            requestedStatus: $status,
        );

        $reasonRequired = $status === PharmacyOrderStatus::CANCELLED->value;
        $dispensedTimestampRequired = $status === PharmacyOrderStatus::DISPENSED->value;

        $updated = DB::transaction(function () use (
            $id,
            $payload,
            $existing,
            $actorId,
            $batchId,
            $reasonRequired,
            $quantityDispensedInputProvided,
            $dispensingNotesInputProvided,
            $dispensedTimestampRequired,
            $stockIssueQuantity,
        ): ?array {
            $updated = $this->pharmacyOrderRepository->update($id, $payload);
            if (! $updated) {
                return null;
            }

            if ($stockIssueQuantity > 0) {
                $this->recordDispenseStockIssue(
                    existing: $existing,
                    updated: $updated,
                    quantityIssued: $stockIssueQuantity,
                    batchId: $batchId,
                    actorId: $actorId,
                );

                $this->recordDepartmentStockConsumption(
                    order: $updated,
                    quantityConsumed: $stockIssueQuantity,
                    actorId: $actorId,
                    batchId: $batchId,
                );
            }

            if (isset($updated['dispensed_unit'])) {
                $payload['dispensed_unit'] = $updated['dispensed_unit'];
            }

            $this->auditLogRepository->write(
                pharmacyOrderId: $id,
                action: 'pharmacy-order.status.updated',
                actorId: $actorId,
                changes: [
                    'status' => [
                        'before' => $existing['status'] ?? null,
                        'after' => $updated['status'] ?? null,
                    ],
                    'status_reason' => [
                        'before' => $existing['status_reason'] ?? null,
                        'after' => $updated['status_reason'] ?? null,
                    ],
                    'quantity_dispensed' => [
                        'before' => $existing['quantity_dispensed'] ?? null,
                        'after' => $updated['quantity_dispensed'] ?? null,
                    ],
                    'dispensed_unit' => [
                        'before' => $existing['dispensed_unit'] ?? null,
                        'after' => $updated['dispensed_unit'] ?? null,
                    ],
                    'dispensing_notes' => [
                        'before' => $existing['dispensing_notes'] ?? null,
                        'after' => $updated['dispensing_notes'] ?? null,
                    ],
                    'dispensed_at' => [
                        'before' => $existing['dispensed_at'] ?? null,
                        'after' => $updated['dispensed_at'] ?? null,
                    ],
                ],
                metadata: [
                    'transition' => [
                        'from' => $existing['status'] ?? null,
                        'to' => $updated['status'] ?? null,
                    ],
                    'reason_required' => $reasonRequired,
                    'reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
                    'quantity_dispensed_input_provided' => $quantityDispensedInputProvided,
                    'dispensing_notes_input_provided' => $dispensingNotesInputProvided,
                    'dispensed_timestamp_required' => $dispensedTimestampRequired,
                    'dispensed_timestamp_provided' => ! empty($updated['dispensed_at'] ?? null),
                    'inventory_issue_quantity' => $stockIssueQuantity > 0 ? $stockIssueQuantity : null,
                    'inventory_issue_unit' => $updated['dispensed_unit'] ?? $existing['dispensed_unit'] ?? $existing['prescribed_unit'] ?? null,
                ],
            );

            // Recorded after the update so the resolver sees the new status.
            // `partially_dispensed` stays in openWorklistValues(), so a partial
            // fill records the work and leaves the patient waiting; only a
            // completed dispense releases the visit.
            $flowSource = self::FLOW_SOURCES_BY_STATUS[$updated['status'] ?? ''] ?? null;
            if ($flowSource !== null) {
                $this->recordFlowTransition->recordForOrder(
                    order: $updated,
                    source: $flowSource,
                    actorId: $actorId,
                    isDispenseComplete: ($updated['status'] ?? null) === PharmacyOrderStatus::DISPENSED->value,
                    metadata: ['pharmacy_order_id' => $id],
                );
            }

            if (($updated['status'] ?? null) === PharmacyOrderStatus::DISPENSED->value) {
                DB::afterCommit(function () use ($id, $updated, $actorId): void {
                    event(new PharmacyOrderDispensed(
                        pharmacyOrderId: $id,
                        patientId: (string) $updated['patient_id'],
                        appointmentId: $updated['appointment_id'] ?? null,
                        orderedByUserId: $updated['ordered_by_user_id'] ?? null,
                        actorId: $actorId,
                        facilityId: $updated['facility_id'] ?? null,
                    ));
                });
            }

            return $updated;
        });

        return $updated;
    }

    private function assertWorkflowTransitionAllowed(string $currentStatus, string $requestedStatus): void
    {
        if (! PharmacyOrderStatus::canTransitionWorkflow($currentStatus, $requestedStatus)) {
            throw new PharmacyOrderStatusUpdateNotAllowedException(
                sprintf(
                    'Pharmacy order cannot move from %s to %s.',
                    $currentStatus !== '' ? str_replace('_', ' ', $currentStatus) : 'unknown status',
                    str_replace('_', ' ', $requestedStatus),
                ),
                'status',
            );
        }
    }

    private function assertRequestedQuantityValid(
        string $currentStatus,
        string $requestedStatus,
        float $quantityPrescribed,
        float $previousQuantityDispensed,
        ?float $quantityDispensed,
        bool $quantityDispensedInputProvided,
    ): void {
        if ($requestedStatus === PharmacyOrderStatus::PARTIALLY_DISPENSED->value) {
            if (! $quantityDispensedInputProvided) {
                throw new PharmacyOrderStatusUpdateNotAllowedException(
                    'Quantity dispensed is required for a partial dispense.',
                    'quantityDispensed',
                );
            }

            $requestedQuantity = round(max((float) $quantityDispensed, 0), 2);

            if ($requestedQuantity <= 0) {
                throw new PharmacyOrderStatusUpdateNotAllowedException(
                    'Partial dispense quantity must be greater than zero.',
                    'quantityDispensed',
                );
            }

            if ($requestedQuantity <= $previousQuantityDispensed) {
                throw new PharmacyOrderStatusUpdateNotAllowedException(
                    'Partial dispense quantity must increase the amount already dispensed.',
                    'quantityDispensed',
                );
            }

            if ($requestedQuantity >= $quantityPrescribed) {
                throw new PharmacyOrderStatusUpdateNotAllowedException(
                    'Use final dispense when the released quantity reaches the prescribed amount.',
                    'quantityDispensed',
                );
            }

            return;
        }

        if ($requestedStatus !== PharmacyOrderStatus::DISPENSED->value) {
            return;
        }

        $requestedQuantity = $quantityDispensedInputProvided
            ? round(max((float) $quantityDispensed, 0), 2)
            : $quantityPrescribed;

        if ($requestedQuantity <= 0) {
            throw new PharmacyOrderStatusUpdateNotAllowedException(
                'Dispensed quantity must be greater than zero.',
                'quantityDispensed',
            );
        }

        if ($requestedQuantity < $quantityPrescribed) {
            throw new PharmacyOrderStatusUpdateNotAllowedException(
                'Use partial dispense until the full prescribed quantity is released.',
                'quantityDispensed',
            );
        }

        if ($requestedQuantity > $quantityPrescribed) {
            throw new PharmacyOrderStatusUpdateNotAllowedException(
                'Dispensed quantity cannot exceed the prescribed quantity.',
                'quantityDispensed',
            );
        }

        if ($currentStatus === PharmacyOrderStatus::PARTIALLY_DISPENSED->value
            && $requestedQuantity <= $previousQuantityDispensed) {
            throw new PharmacyOrderStatusUpdateNotAllowedException(
                'Final dispense quantity must be greater than the amount already partially dispensed.',
                'quantityDispensed',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function assertPolicyWorkflowReady(array $order, string $requestedStatus): void
    {
        if (! in_array(
            $requestedStatus,
            [
                PharmacyOrderStatus::IN_PREPARATION->value,
                PharmacyOrderStatus::PARTIALLY_DISPENSED->value,
                PharmacyOrderStatus::DISPENSED->value,
            ],
            true,
        )) {
            return;
        }

        if (! ApprovedMedicineGovernance::workflowBlocked($order)) {
            return;
        }

        $formularyDecision = strtolower(trim((string) ($order['formulary_decision_status'] ?? 'not_reviewed')));

        if ($formularyDecision === 'not_reviewed') {
            throw new PharmacyOrderStatusUpdateNotAllowedException(
                'Policy review must be completed before this order can move into preparation or release.',
                'policy',
            );
        }

        if ($formularyDecision === 'non_formulary') {
            throw new PharmacyOrderStatusUpdateNotAllowedException(
                'This order still carries a non-formulary policy decision. Resolve the policy path before preparation or release.',
                'policy',
            );
        }

        if ($formularyDecision === 'restricted') {
            throw new PharmacyOrderStatusUpdateNotAllowedException(
                'This order is still marked as restricted in policy review. Resolve the restriction before preparation or release.',
                'policy',
            );
        }

    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function resolveDispensedUnit(array $order, ?string $requestedUnit): ?string
    {
        $normalizedRequestedUnit = $this->normalizeUnit($requestedUnit);
        $prescribedUnit = $this->normalizeUnit($order['prescribed_unit'] ?? null);
        $existingDispensedUnit = $this->normalizeUnit($order['dispensed_unit'] ?? null);

        if ($normalizedRequestedUnit !== null) {
            return $normalizedRequestedUnit;
        }

        return $existingDispensedUnit ?? $prescribedUnit;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $payload
     */
    private function resolveStockIssueQuantity(
        array $existing,
        array $payload,
        string $requestedStatus
    ): float {
        if (! in_array(
            $requestedStatus,
            [
                PharmacyOrderStatus::PARTIALLY_DISPENSED->value,
                PharmacyOrderStatus::DISPENSED->value,
            ],
            true,
        )) {
            return 0.0;
        }

        $previousQuantity = round((float) ($existing['quantity_dispensed'] ?? 0), 2);
        $nextQuantity = round((float) ($payload['quantity_dispensed'] ?? $previousQuantity), 2);
        $delta = round($nextQuantity - $previousQuantity, 2);

        if ($delta < 0) {
            throw new PharmacyOrderStatusUpdateNotAllowedException(
                'Quantity dispensed cannot be reduced after stock has already been issued.',
                'quantityDispensed',
            );
        }

        return max($delta, 0.0);
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $updated
     */
    private function recordDispenseStockIssue(
        array $existing,
        array $updated,
        float $quantityIssued,
        ?string $batchId,
        ?int $actorId
    ): void {
        [$dispenseTargetCode, $dispenseTargetName] = $this->resolveDispenseTargetMedication($updated);

        $inventoryItem = $this->inventoryItemRepository->findBestActiveMatchByCodeOrName(
            $dispenseTargetCode,
            $dispenseTargetName,
        );

        if (! $inventoryItem) {
            throw new InventoryItemNotFoundException(
                'No active inventory item matched the medicine that will be dispensed.'
            );
        }

        $isPartialDispense = ($updated['status'] ?? null) === PharmacyOrderStatus::PARTIALLY_DISPENSED->value;
        $movementReason = $isPartialDispense
            ? 'Pharmacy partial dispense release.'
            : 'Pharmacy dispense release.';

        $resolvedDispenseUnit = $this->normalizeUnit($updated['dispensed_unit'] ?? $existing['dispensed_unit'] ?? $existing['prescribed_unit'] ?? null);

        $payload = [
            'item_id' => (string) $inventoryItem['id'],
            'source_warehouse_id' => $inventoryItem['default_warehouse_id'] ?? null,
            'quantity' => $quantityIssued,
            'unit' => $resolvedDispenseUnit,
            'reason' => $movementReason,
            'notes' => $updated['dispensing_notes'] ?? null,
            'occurred_at' => $updated['dispensed_at'] ?? now(),
            'metadata' => [
                'source_module' => 'pharmacy',
                'source_action' => 'pharmacy-order.status.updated',
                'pharmacy_order_id' => $updated['id'] ?? null,
                'pharmacy_order_number' => $updated['order_number'] ?? null,
                'patient_id' => $updated['patient_id'] ?? null,
                'appointment_id' => $updated['appointment_id'] ?? null,
                'admission_id' => $updated['admission_id'] ?? null,
                'status_from' => $existing['status'] ?? null,
                'status_to' => $updated['status'] ?? null,
                'quantity_dispensed_before' => $existing['quantity_dispensed'] ?? null,
                'quantity_dispensed_after' => $updated['quantity_dispensed'] ?? null,
                'dispensed_unit' => $resolvedDispenseUnit,
                'dispense_target_code' => $dispenseTargetCode,
                'dispense_target_name' => $dispenseTargetName,
                'substitution_made' => $this->orderIndicatesSubstitution($updated),
            ],
        ];

        if ($batchId !== null) {
            $payload['batch_id'] = $batchId;
            $this->inventoryBatchStockService->issueExactBatch($payload, $actorId);
        } else {
            $this->inventoryBatchStockService->issue($payload, $actorId);
        }
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveDispenseTargetMedication(array $order): array
    {
        if ($this->orderIndicatesSubstitution($order)) {
            $substitutedCode = $this->nullableString($order['substituted_medication_code'] ?? null);
            $substitutedName = $this->nullableString($order['substituted_medication_name'] ?? null);

            if ($substitutedCode !== null || $substitutedName !== null) {
                return [$substitutedCode, $substitutedName];
            }
        }

        return [
            $this->nullableString($order['medication_code'] ?? null),
            $this->nullableString($order['medication_name'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function orderIndicatesSubstitution(array $order): bool
    {
        if ((bool) ($order['substitution_made'] ?? false)) {
            return true;
        }

        return str_contains(
            strtolower((string) ($order['dispensing_notes'] ?? '')),
            'substitution: yes',
        );
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function recordDepartmentStockConsumption(
        array $order,
        float $quantityConsumed,
        ?int $actorId,
        ?string $batchId = null,
    ): void {
        $departmentId = $this->resolveDepartmentId($order);
        if ($departmentId === null) {
            return;
        }

        [$itemCode, $itemName] = $this->resolveDispenseTargetMedication($order);
        $inventoryItem = $this->inventoryItemRepository->findBestActiveMatchByCodeOrName(
            $itemCode,
            $itemName,
        );

        if (! $inventoryItem) {
            return;
        }

        $resolvedDispenseUnit = $this->normalizeUnit(
            $order['dispensed_unit'] ?? $order['prescribed_unit'] ?? null,
        );

        $this->departmentStockService->recordConsumption(
            tenantId: $this->platformScopeContext->tenantId(),
            facilityId: $this->platformScopeContext->facilityId(),
            departmentId: $departmentId,
            itemId: (string) $inventoryItem['id'],
            quantity: $quantityConsumed,
            batchId: $batchId,
            source: 'pharmacy_dispense',
            sourceId: $order['id'] ?? null,
            actorId: $actorId,
            notes: 'Pharmacy dispense – order '.($order['order_number'] ?? $order['id'] ?? ''),
        );
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function resolveDepartmentId(array $order): ?string
    {
        $appointmentId = $order['appointment_id'] ?? null;
        if ($appointmentId !== null) {
            $appointment = AppointmentModel::query()->find($appointmentId);
            $departmentName = $appointment['department'] ?? null;
            if ($departmentName !== null && trim((string) $departmentName) !== '') {
                $department = $this->departmentRepository->findActiveByName((string) $departmentName);
                if ($department !== null) {
                    return (string) $department['id'];
                }
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeUnit(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : mb_strtolower($normalized);
    }
}

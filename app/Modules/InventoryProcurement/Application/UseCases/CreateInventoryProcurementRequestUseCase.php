<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryProcurementWorkflowException;
use App\Modules\InventoryProcurement\Application\Services\ProcurementApprovalRoutingService;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryProcurementRequestAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryProcurementRequestRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryProcurementRequestStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use Illuminate\Support\Str;
use RuntimeException;

class CreateInventoryProcurementRequestUseCase
{
    private const ACTIVE_SOURCE_SHORTAGE_STATUSES = [
        InventoryProcurementRequestStatus::PENDING_APPROVAL->value,
        InventoryProcurementRequestStatus::APPROVED->value,
        InventoryProcurementRequestStatus::ORDERED->value,
    ];

    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventoryItemRepository,
        private readonly InventoryProcurementRequestRepositoryInterface $inventoryProcurementRequestRepository,
        private readonly InventoryProcurementRequestAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly ProcurementApprovalRoutingService $routingService,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $item = $this->resolveOrCreateItem($payload);
        $this->guardAgainstDuplicateActiveSourceShortageRequest($payload);

        $quantity = (float) $payload['requested_quantity'];
        $unitCostEstimate = isset($payload['unit_cost_estimate']) && $payload['unit_cost_estimate'] !== null
            ? (float) $payload['unit_cost_estimate']
            : null;
        $totalCostEstimate = $unitCostEstimate !== null ? $quantity * $unitCostEstimate : null;

        // Determine requesting department from actor's staff profile
        $requestingDepartmentId = null;
        if ($actorId) {
            $staffProfile = StaffProfileModel::where('user_id', $actorId)->first();
            if ($staffProfile?->department_id) {
                $requestingDepartmentId = $staffProfile->department_id;
            }
        }

        // Determine approval routing and duplicate check
        $approvalRouting = null;
        $duplicateCheckHash = null;
        if ($requestingDepartmentId && $item['id']) {
            $approvalRouting = $this->routingService->determineApprovalRoute(
                $requestingDepartmentId,
                $item['id'],
                $quantity,
                $unitCostEstimate,
            );
            $duplicateCheckHash = $this->routingService->generateDuplicateCheckHash(
                $requestingDepartmentId,
                $item['id'],
                InventoryProcurementRequestStatus::PENDING_APPROVAL->value,
            );
        }

        $requestPayload = [
            'request_number' => $this->generateRequestNumber(),
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'item_id' => $item['id'],
            'requesting_department_id' => $requestingDepartmentId,
            'requested_quantity' => $quantity,
            'unit_cost_estimate' => $unitCostEstimate,
            'total_cost_estimate' => $totalCostEstimate,
            'requested_by_user_id' => $actorId,
            'status' => InventoryProcurementRequestStatus::PENDING_APPROVAL->value,
            'approval_route' => $approvalRouting['route'] ?? 'needs_review',
            'is_catalog_item' => $approvalRouting['is_catalog_item'] ?? false,
            'duplicate_check_hash' => $duplicateCheckHash,
            'needed_by' => $payload['needed_by'] ?? null,
            'supplier_id' => $payload['supplier_id'] ?? null,
            'supplier_name' => $payload['supplier_name'] ?? null,
            'source_department_requisition_id' => $payload['source_department_requisition_id'] ?? null,
            'source_department_requisition_line_id' => $payload['source_department_requisition_line_id'] ?? null,
            'notes' => $payload['notes'] ?? null,
        ];

        $created = $this->inventoryProcurementRequestRepository->create($requestPayload);

        $this->auditLogRepository->write(
            inventoryProcurementRequestId: $created['id'],
            action: 'inventory-procurement-request.created',
            actorId: $actorId,
            changes: [
                'after' => $created,
            ],
        );

        return $created;
    }

    private function guardAgainstDuplicateActiveSourceShortageRequest(array $payload): void
    {
        $lineId = trim((string) ($payload['source_department_requisition_line_id'] ?? ''));
        if ($lineId === '') {
            return;
        }

        $existingByLine = $this->inventoryProcurementRequestRepository
            ->latestBySourceDepartmentRequisitionLineIds([$lineId]);
        $existing = $existingByLine[$lineId] ?? null;

        if (! $existing) {
            return;
        }

        $status = (string) ($existing['status'] ?? '');
        if (in_array($status, self::ACTIVE_SOURCE_SHORTAGE_STATUSES, true)) {
            $requestNumber = $existing['request_number'] ?? 'an active procurement request';

            throw new InventoryProcurementWorkflowException(
                "This department shortage already has {$requestNumber} in ".str_replace('_', ' ', $status).' status.',
            );
        }
    }

    private function resolveOrCreateItem(array $payload): array
    {
        $itemId = $payload['item_id'] ?? null;
        if (is_string($itemId) && trim($itemId) !== '') {
            $item = $this->inventoryItemRepository->findById($itemId);
            if (! $item) {
                throw new InventoryItemNotFoundException('Inventory item not found for procurement request.');
            }

            return $item;
        }

        $itemName = trim((string) ($payload['item_name'] ?? ''));
        if ($itemName === '') {
            throw new InventoryItemNotFoundException('Provide itemId or itemName for procurement request.');
        }

        $unit = trim((string) ($payload['unit'] ?? ''));
        if ($unit === '') {
            throw new InventoryItemNotFoundException('Unit is required when creating a new inventory item from request.');
        }

        return $this->inventoryItemRepository->create([
            'item_code' => $this->generateItemCode(),
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'item_name' => $itemName,
            'category' => $payload['category'] ?? null,
            'unit' => $unit,
            'current_stock' => 0,
            'reorder_level' => $payload['reorder_level'] ?? 0,
            'max_stock_level' => null,
            'status' => 'active',
        ]);
    }

    private function generateItemCode(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'ITM'.now()->format('Ymd').strtoupper(Str::random(5));
            if (! $this->inventoryItemRepository->existsByItemCode($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique inventory item code.');
    }

    private function generateRequestNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'PRQ'.now()->format('Ymd').strtoupper(Str::random(5));
            if (! $this->inventoryProcurementRequestRepository->existsByRequestNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique procurement request number.');
    }
}

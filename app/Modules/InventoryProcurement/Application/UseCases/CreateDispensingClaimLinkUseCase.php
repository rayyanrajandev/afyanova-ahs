<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDispensingClaimLinkRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateDispensingClaimLinkUseCase
{
    public function __construct(
        private readonly InventoryDispensingClaimLinkRepositoryInterface $linkRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?string $actorId): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return $this->linkRepository->create([
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'stock_movement_id' => $payload['stock_movement_id'] ?? null,
            'pharmacy_order_id' => $payload['pharmacy_order_id'] ?? null,
            'item_id' => $payload['item_id'],
            'batch_id' => $payload['batch_id'] ?? null,
            'quantity_dispensed' => $payload['quantity_dispensed'],
            'unit' => $payload['unit'] ?? null,
            'unit_cost' => $payload['unit_cost'] ?? null,
            'total_cost' => $payload['total_cost'] ?? null,
            'patient_id' => $payload['patient_id'],
            'admission_id' => $payload['admission_id'] ?? null,
            'appointment_id' => $payload['appointment_id'] ?? null,
            'insurance_claim_id' => $payload['insurance_claim_id'] ?? null,
            'billing_invoice_id' => $payload['billing_invoice_id'] ?? null,
            'nhif_code' => $payload['nhif_code'] ?? null,
            'payer_type' => $payload['payer_type'] ?? null,
            'payer_name' => $payload['payer_name'] ?? null,
            'payer_reference' => $payload['payer_reference'] ?? null,
            'claim_status' => $payload['claim_status'] ?? 'pending',
            'notes' => $payload['notes'] ?? null,
            'created_by_user_id' => $actorId,
        ]);
    }
}

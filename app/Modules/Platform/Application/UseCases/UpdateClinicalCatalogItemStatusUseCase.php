<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Application\Support\ClinicalCatalogBillingLinkEnricher;
use App\Modules\Platform\Domain\Repositories\ClinicalCatalogItemAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\ClinicalCatalogItemRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogItemStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateClinicalCatalogItemStatusUseCase
{
    public function __construct(
        private readonly ClinicalCatalogItemRepositoryInterface $repository,
        private readonly ClinicalCatalogItemAuditLogRepositoryInterface $auditLogRepository,
        private readonly ClinicalCatalogBillingLinkEnricher $billingLinkEnricher,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $catalogType, string $status, ?string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->repository->findById($id);
        if (! $existing || ($existing['catalog_type'] ?? null) !== $catalogType) {
            return null;
        }

        $updated = $this->repository->update($id, [
            'status' => $status,
            'status_reason' => $reason,
        ]);
        if (! $updated) {
            return null;
        }

        $reasonRequired = in_array($status, [
            ClinicalCatalogItemStatus::INACTIVE->value,
            ClinicalCatalogItemStatus::RETIRED->value,
        ], true);

        $this->auditLogRepository->write(
            clinicalCatalogItemId: $id,
            action: 'platform.clinical-catalog-item.status.updated',
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
            ],
            metadata: [
                'catalogType' => $catalogType,
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'reason_required' => $reasonRequired,
                'reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
            ],
        );

        return $this->billingLinkEnricher->enrich($updated);
    }
}

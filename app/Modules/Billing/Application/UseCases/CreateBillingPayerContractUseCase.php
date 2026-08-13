<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Exceptions\DuplicateBillingPayerContractCodeException;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use App\Modules\Billing\Domain\ValueObjects\BillingPayerContractStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateBillingPayerContractUseCase
{
    public function __construct(
        private readonly BillingPayerContractRepositoryInterface $repository,
        private readonly BillingPayerContractAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();
        $contractCode = $this->normalizeCode((string) $payload['contract_code']);

        if ($this->repository->existsByContractCode($contractCode, $tenantId, $facilityId)) {
            throw new DuplicateBillingPayerContractCodeException('Payer contract code already exists in the current scope.');
        }

        $created = $this->repository->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'contract_code' => $contractCode,
            'contract_name' => trim((string) $payload['contract_name']),
            'payer_type' => $this->normalizePayerType((string) $payload['payer_type']),
            'payer_name' => trim((string) $payload['payer_name']),
            'payer_plan_code' => $this->nullableTrimmedValue($payload['payer_plan_code'] ?? null),
            'payer_plan_name' => $this->nullableTrimmedValue($payload['payer_plan_name'] ?? null),
            'currency_code' => $this->normalizeCurrency((string) $payload['currency_code']),
            'default_coverage_percent' => $this->normalizeNullableDecimal($payload['default_coverage_percent'] ?? null),
            'default_copay_type' => $this->nullableTrimmedValue($payload['default_copay_type'] ?? null),
            'default_copay_value' => $this->normalizeNullableDecimal($payload['default_copay_value'] ?? null),
            'requires_pre_authorization' => (bool) ($payload['requires_pre_authorization'] ?? false),
            'claim_submission_deadline_days' => $this->normalizeNullableInteger($payload['claim_submission_deadline_days'] ?? null),
            'settlement_cycle_days' => $this->normalizeNullableInteger($payload['settlement_cycle_days'] ?? null),
            'effective_from' => $payload['effective_from'] ?? null,
            'effective_to' => $payload['effective_to'] ?? null,
            'terms_and_notes' => $this->nullableTrimmedValue($payload['terms_and_notes'] ?? null),
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
            'status' => BillingPayerContractStatus::ACTIVE->value,
            'status_reason' => null,
        ]);

        $this->auditLogRepository->write(
            billingPayerContractId: $created['id'],
            action: 'billing-payer-contract.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
        );

        return $created;
    }

    private function normalizeCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function normalizeCurrency(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function normalizePayerType(string $value): string
    {
        return strtolower(trim($value));
    }

    private function normalizeNullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    private function normalizeNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max((int) $value, 0);
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $contract): array
    {
        $tracked = [
            'tenant_id',
            'facility_id',
            'contract_code',
            'contract_name',
            'payer_type',
            'payer_name',
            'payer_plan_code',
            'payer_plan_name',
            'currency_code',
            'default_coverage_percent',
            'default_copay_type',
            'default_copay_value',
            'requires_pre_authorization',
            'claim_submission_deadline_days',
            'settlement_cycle_days',
            'effective_from',
            'effective_to',
            'terms_and_notes',
            'metadata',
            'status',
            'status_reason',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $contract[$field] ?? null;
        }

        return $result;
    }
}

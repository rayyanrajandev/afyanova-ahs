<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Exceptions\DuplicateBillingPayerContractCodeException;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateBillingPayerContractUseCase
{
    public function __construct(
        private readonly BillingPayerContractRepositoryInterface $repository,
        private readonly BillingPayerContractAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->repository->findById($id);
        if (! $existing) {
            return null;
        }

        if (array_key_exists('contract_code', $payload)) {
            $contractCode = $this->normalizeCode((string) $payload['contract_code']);
            $tenantId = $this->platformScopeContext->tenantId();
            $facilityId = $this->platformScopeContext->facilityId();

            if ($this->repository->existsByContractCode($contractCode, $tenantId, $facilityId, $id)) {
                throw new DuplicateBillingPayerContractCodeException('Payer contract code already exists in the current scope.');
            }
        }

        $updateData = $this->toUpdateData($payload);
        if ($updateData === []) {
            return $existing;
        }

        $updated = $this->repository->update($id, $updateData);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            billingPayerContractId: $id,
            action: 'billing-payer-contract.updated',
            actorId: $actorId,
            changes: $this->diffChanges($existing, $updated),
        );

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function toUpdateData(array $payload): array
    {
        $updateData = [];

        if (array_key_exists('contract_code', $payload)) {
            $updateData['contract_code'] = $this->normalizeCode((string) $payload['contract_code']);
        }
        if (array_key_exists('contract_name', $payload)) {
            $updateData['contract_name'] = trim((string) $payload['contract_name']);
        }
        if (array_key_exists('payer_type', $payload)) {
            $updateData['payer_type'] = $this->normalizePayerType((string) $payload['payer_type']);
        }
        if (array_key_exists('payer_name', $payload)) {
            $updateData['payer_name'] = trim((string) $payload['payer_name']);
        }
        if (array_key_exists('payer_plan_code', $payload)) {
            $updateData['payer_plan_code'] = $this->nullableTrimmedValue($payload['payer_plan_code']);
        }
        if (array_key_exists('payer_plan_name', $payload)) {
            $updateData['payer_plan_name'] = $this->nullableTrimmedValue($payload['payer_plan_name']);
        }
        if (array_key_exists('currency_code', $payload)) {
            $updateData['currency_code'] = $this->normalizeCurrency((string) $payload['currency_code']);
        }
        if (array_key_exists('default_coverage_percent', $payload)) {
            $updateData['default_coverage_percent'] = $this->normalizeNullableDecimal($payload['default_coverage_percent']);
        }
        if (array_key_exists('default_copay_type', $payload)) {
            $updateData['default_copay_type'] = $this->nullableTrimmedValue($payload['default_copay_type']);
        }
        if (array_key_exists('default_copay_value', $payload)) {
            $updateData['default_copay_value'] = $this->normalizeNullableDecimal($payload['default_copay_value']);
        }
        if (array_key_exists('requires_pre_authorization', $payload)) {
            $updateData['requires_pre_authorization'] = (bool) $payload['requires_pre_authorization'];
        }
        if (array_key_exists('claim_submission_deadline_days', $payload)) {
            $updateData['claim_submission_deadline_days'] = $this->normalizeNullableInteger($payload['claim_submission_deadline_days']);
        }
        if (array_key_exists('settlement_cycle_days', $payload)) {
            $updateData['settlement_cycle_days'] = $this->normalizeNullableInteger($payload['settlement_cycle_days']);
        }
        if (array_key_exists('effective_from', $payload)) {
            $updateData['effective_from'] = $payload['effective_from'];
        }
        if (array_key_exists('effective_to', $payload)) {
            $updateData['effective_to'] = $payload['effective_to'];
        }
        if (array_key_exists('terms_and_notes', $payload)) {
            $updateData['terms_and_notes'] = $this->nullableTrimmedValue($payload['terms_and_notes']);
        }
        if (array_key_exists('metadata', $payload)) {
            $updateData['metadata'] = is_array($payload['metadata']) ? $payload['metadata'] : null;
        }

        return $updateData;
    }

    /**
     * @return array<string, mixed>
     */
    private function diffChanges(array $before, array $after): array
    {
        $fields = [
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
        ];

        $changes = [];
        foreach ($fields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;

            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[$field] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
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
}

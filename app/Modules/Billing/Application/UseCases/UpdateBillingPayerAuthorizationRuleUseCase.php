<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Exceptions\DuplicateBillingPayerAuthorizationRuleCodeException;
use App\Modules\Billing\Domain\Repositories\BillingPayerAuthorizationRuleAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerAuthorizationRuleRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateBillingPayerAuthorizationRuleUseCase
{
    public function __construct(
        private readonly BillingPayerAuthorizationRuleRepositoryInterface $ruleRepository,
        private readonly BillingPayerAuthorizationRuleAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $billingPayerContractId,
        string $id,
        array $payload,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->ruleRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if (($existing['billing_payer_contract_id'] ?? null) !== $billingPayerContractId) {
            return null;
        }

        if (array_key_exists('rule_code', $payload)) {
            $ruleCode = $this->normalizeCode((string) $payload['rule_code']);
            if ($this->ruleRepository->existsByRuleCode($billingPayerContractId, $ruleCode, $id)) {
                throw new DuplicateBillingPayerAuthorizationRuleCodeException(
                    'Authorization rule code already exists for this payer contract.',
                );
            }
        }

        $updateData = $this->toUpdateData($payload);
        if ($updateData === []) {
            return $existing;
        }

        $updated = $this->ruleRepository->update($id, $updateData);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            billingPayerAuthorizationRuleId: $id,
            action: 'billing-payer-authorization-rule.updated',
            actorId: $actorId,
            changes: $this->diffChanges($existing, $updated),
            metadata: [
                'billing_payer_contract_id' => $billingPayerContractId,
            ],
        );

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function toUpdateData(array $payload): array
    {
        $updateData = [];

        if (array_key_exists('billing_service_catalog_item_id', $payload)) {
            $updateData['billing_service_catalog_item_id'] = $payload['billing_service_catalog_item_id'];
        }
        if (array_key_exists('rule_code', $payload)) {
            $updateData['rule_code'] = $this->normalizeCode((string) $payload['rule_code']);
        }
        if (array_key_exists('rule_name', $payload)) {
            $updateData['rule_name'] = trim((string) $payload['rule_name']);
        }
        if (array_key_exists('service_code', $payload)) {
            $updateData['service_code'] = $this->normalizeNullableCode($payload['service_code']);
        }
        if (array_key_exists('service_type', $payload)) {
            $updateData['service_type'] = $this->nullableTrimmedValue($payload['service_type']);
        }
        if (array_key_exists('department', $payload)) {
            $updateData['department'] = $this->nullableTrimmedValue($payload['department']);
        }
        if (array_key_exists('diagnosis_code', $payload)) {
            $updateData['diagnosis_code'] = $this->nullableTrimmedValue($payload['diagnosis_code']);
        }
        if (array_key_exists('priority', $payload)) {
            $updateData['priority'] = $this->nullableTrimmedValue($payload['priority']);
        }
        if (array_key_exists('min_patient_age_years', $payload)) {
            $updateData['min_patient_age_years'] = $this->normalizeNullableInteger($payload['min_patient_age_years']);
        }
        if (array_key_exists('max_patient_age_years', $payload)) {
            $updateData['max_patient_age_years'] = $this->normalizeNullableInteger($payload['max_patient_age_years']);
        }
        if (array_key_exists('gender', $payload)) {
            $updateData['gender'] = $this->nullableTrimmedValue($payload['gender']);
        }
        if (array_key_exists('amount_threshold', $payload)) {
            $updateData['amount_threshold'] = $this->normalizeNullableDecimal($payload['amount_threshold']);
        }
        if (array_key_exists('quantity_limit', $payload)) {
            $updateData['quantity_limit'] = $this->normalizeNullableInteger($payload['quantity_limit']);
        }
        if (array_key_exists('coverage_decision', $payload)) {
            $updateData['coverage_decision'] = $this->normalizeCoverageDecision($payload['coverage_decision']);
        }
        if (array_key_exists('coverage_percent_override', $payload)) {
            $updateData['coverage_percent_override'] = $this->normalizeNullablePercent($payload['coverage_percent_override']);
        }
        if (array_key_exists('copay_type', $payload)) {
            $updateData['copay_type'] = $this->nullableTrimmedValue($payload['copay_type']);
        }
        if (array_key_exists('copay_value', $payload)) {
            $updateData['copay_value'] = $this->normalizeNullableDecimal($payload['copay_value']);
        }
        if (array_key_exists('benefit_limit_amount', $payload)) {
            $updateData['benefit_limit_amount'] = $this->normalizeNullableDecimal($payload['benefit_limit_amount']);
        }
        if (array_key_exists('effective_from', $payload)) {
            $updateData['effective_from'] = $this->nullableTrimmedValue($payload['effective_from']);
        }
        if (array_key_exists('effective_to', $payload)) {
            $updateData['effective_to'] = $this->nullableTrimmedValue($payload['effective_to']);
        }
        if (array_key_exists('requires_authorization', $payload)) {
            $updateData['requires_authorization'] = (bool) $payload['requires_authorization'];
        }
        if (array_key_exists('auto_approve', $payload)) {
            $updateData['auto_approve'] = (bool) $payload['auto_approve'];
        }
        if (array_key_exists('authorization_validity_days', $payload)) {
            $updateData['authorization_validity_days'] = $this->normalizeNullableInteger($payload['authorization_validity_days']);
        }
        if (array_key_exists('rule_notes', $payload)) {
            $updateData['rule_notes'] = $this->nullableTrimmedValue($payload['rule_notes']);
        }
        if (array_key_exists('rule_expression', $payload)) {
            $updateData['rule_expression'] = is_array($payload['rule_expression']) ? $payload['rule_expression'] : null;
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
            'billing_service_catalog_item_id',
            'rule_code',
            'rule_name',
            'service_code',
            'service_type',
            'department',
            'diagnosis_code',
            'priority',
            'min_patient_age_years',
            'max_patient_age_years',
            'gender',
            'amount_threshold',
            'quantity_limit',
            'coverage_decision',
            'coverage_percent_override',
            'copay_type',
            'copay_value',
            'benefit_limit_amount',
            'effective_from',
            'effective_to',
            'requires_authorization',
            'auto_approve',
            'authorization_validity_days',
            'rule_notes',
            'rule_expression',
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

    private function normalizeNullableCode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));

        return $normalized === '' ? null : $normalized;
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

    private function normalizeNullablePercent(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round(min(max((float) $value, 0), 100), 2);
    }

    private function normalizeCoverageDecision(mixed $value): ?string
    {
        $normalized = $this->nullableTrimmedValue($value);

        return $normalized === null ? null : $normalized;
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

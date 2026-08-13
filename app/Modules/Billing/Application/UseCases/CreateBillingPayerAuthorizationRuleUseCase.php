<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Exceptions\DuplicateBillingPayerAuthorizationRuleCodeException;
use App\Modules\Billing\Domain\Repositories\BillingPayerAuthorizationRuleAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerAuthorizationRuleRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use App\Modules\Billing\Domain\ValueObjects\BillingPayerAuthorizationRuleStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateBillingPayerAuthorizationRuleUseCase
{
    public function __construct(
        private readonly BillingPayerContractRepositoryInterface $contractRepository,
        private readonly BillingPayerAuthorizationRuleRepositoryInterface $ruleRepository,
        private readonly BillingPayerAuthorizationRuleAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $billingPayerContractId,
        array $payload,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $contract = $this->contractRepository->findById($billingPayerContractId);
        if (! $contract) {
            return null;
        }

        $ruleCode = $this->normalizeCode((string) $payload['rule_code']);
        if ($this->ruleRepository->existsByRuleCode($billingPayerContractId, $ruleCode)) {
            throw new DuplicateBillingPayerAuthorizationRuleCodeException(
                'Authorization rule code already exists for this payer contract.',
            );
        }

        $created = $this->ruleRepository->create([
            'billing_payer_contract_id' => $billingPayerContractId,
            'tenant_id' => $contract['tenant_id'] ?? null,
            'facility_id' => $contract['facility_id'] ?? null,
            'billing_service_catalog_item_id' => $payload['billing_service_catalog_item_id'] ?? null,
            'rule_code' => $ruleCode,
            'rule_name' => trim((string) $payload['rule_name']),
            'service_code' => $this->normalizeNullableCode($payload['service_code'] ?? null),
            'service_type' => $this->nullableTrimmedValue($payload['service_type'] ?? null),
            'department' => $this->nullableTrimmedValue($payload['department'] ?? null),
            'diagnosis_code' => $this->nullableTrimmedValue($payload['diagnosis_code'] ?? null),
            'priority' => $this->nullableTrimmedValue($payload['priority'] ?? null),
            'min_patient_age_years' => $this->normalizeNullableInteger($payload['min_patient_age_years'] ?? null),
            'max_patient_age_years' => $this->normalizeNullableInteger($payload['max_patient_age_years'] ?? null),
            'gender' => $this->nullableTrimmedValue($payload['gender'] ?? null),
            'amount_threshold' => $this->normalizeNullableDecimal($payload['amount_threshold'] ?? null),
            'quantity_limit' => $this->normalizeNullableInteger($payload['quantity_limit'] ?? null),
            'coverage_decision' => $this->normalizeCoverageDecision($payload['coverage_decision'] ?? null),
            'coverage_percent_override' => $this->normalizeNullablePercent($payload['coverage_percent_override'] ?? null),
            'copay_type' => $this->nullableTrimmedValue($payload['copay_type'] ?? null),
            'copay_value' => $this->normalizeNullableDecimal($payload['copay_value'] ?? null),
            'benefit_limit_amount' => $this->normalizeNullableDecimal($payload['benefit_limit_amount'] ?? null),
            'effective_from' => $this->nullableTrimmedValue($payload['effective_from'] ?? null),
            'effective_to' => $this->nullableTrimmedValue($payload['effective_to'] ?? null),
            'requires_authorization' => (bool) ($payload['requires_authorization'] ?? true),
            'auto_approve' => (bool) ($payload['auto_approve'] ?? false),
            'authorization_validity_days' => $this->normalizeNullableInteger($payload['authorization_validity_days'] ?? null),
            'rule_notes' => $this->nullableTrimmedValue($payload['rule_notes'] ?? null),
            'rule_expression' => is_array($payload['rule_expression'] ?? null) ? $payload['rule_expression'] : null,
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
            'status' => BillingPayerAuthorizationRuleStatus::ACTIVE->value,
            'status_reason' => null,
        ]);

        $this->auditLogRepository->write(
            billingPayerAuthorizationRuleId: $created['id'],
            action: 'billing-payer-authorization-rule.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
            metadata: [
                'billing_payer_contract_id' => $billingPayerContractId,
            ],
        );

        return $created;
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

    private function normalizeCoverageDecision(mixed $value): string
    {
        $normalized = $this->nullableTrimmedValue($value);

        return $normalized ?? 'covered_with_rule';
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
    private function extractTrackedFields(array $rule): array
    {
        $tracked = [
            'billing_payer_contract_id',
            'tenant_id',
            'facility_id',
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
            'status',
            'status_reason',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $rule[$field] ?? null;
        }

        return $result;
    }
}

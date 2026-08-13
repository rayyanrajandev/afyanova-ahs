<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Exceptions\DuplicatePatientInsuranceMemberException;
use App\Modules\Billing\Domain\Repositories\PatientInsuranceRepositoryInterface;
use App\Modules\Billing\Infrastructure\Repositories\PatientInsuranceAuditEventRepository;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreatePatientInsuranceRecordUseCase
{
    public function __construct(
        private readonly PatientInsuranceRepositoryInterface $repository,
        private readonly PatientInsuranceAuditEventRepository $auditEventRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $patientId, array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $this->assertUniqueMemberIdIfConfigured($payload);

        $created = $this->repository->create([
            ...$this->normalizedPayload($payload),
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'patient_id' => $patientId,
            'status' => $this->normalizeCode($payload['status'] ?? 'active'),
            'verification_status' => $this->normalizeCode($payload['verification_status'] ?? 'unverified'),
        ]);

        $this->auditEventRepository->write(
            patientInsuranceRecordId: $created['id'],
            patientId: $patientId,
            action: 'patient-insurance.created',
            actorId: $actorId,
            changes: ['after' => $this->trackedFields($created)],
        );

        return $created;
    }

    private function assertUniqueMemberIdIfConfigured(array $payload, ?string $excludeRecordId = null): void
    {
        if (! (bool) config('patient_insurance.unique_member_id', false)) {
            return;
        }

        $memberId = $this->nullableText($payload['member_id'] ?? null);
        if ($memberId === null) {
            return;
        }

        $duplicates = $this->repository->findActiveByMemberId(
            memberId: $memberId,
            tenantId: $this->platformScopeContext->tenantId(),
            excludeRecordId: $excludeRecordId,
        );

        if ($duplicates === []) {
            return;
        }

        throw new DuplicatePatientInsuranceMemberException($duplicates);
    }

    private function normalizedPayload(array $payload): array
    {
        return [
            'billing_payer_contract_id' => $payload['billing_payer_contract_id'] ?? null,
            'insurance_type' => $this->normalizeCode($payload['insurance_type'] ?? 'insurance'),
            'insurance_provider' => $this->nullableText($payload['insurance_provider'] ?? null),
            'provider_code' => $this->normalizeNullableCode($payload['provider_code'] ?? null),
            'plan_name' => $this->nullableText($payload['plan_name'] ?? null),
            'policy_number' => $this->nullableText($payload['policy_number'] ?? null),
            'member_id' => $this->nullableText($payload['member_id'] ?? null),
            'principal_member_name' => $this->nullableText($payload['principal_member_name'] ?? null),
            'relationship_to_principal' => $this->normalizeNullableCode($payload['relationship_to_principal'] ?? null),
            'card_number' => $this->nullableText($payload['card_number'] ?? null),
            'effective_date' => $payload['effective_date'] ?? null,
            'expiry_date' => $payload['expiry_date'] ?? null,
            'coverage_level' => $this->nullableText($payload['coverage_level'] ?? null),
            'copay_percent' => $this->nullableDecimal($payload['copay_percent'] ?? null),
            'coverage_limit_amount' => $this->nullableDecimal($payload['coverage_limit_amount'] ?? null),
            'verification_date' => $payload['verification_date'] ?? null,
            'verification_source' => $this->nullableText($payload['verification_source'] ?? null),
            'verification_reference' => $this->nullableText($payload['verification_reference'] ?? null),
            'last_verified_at' => $payload['last_verified_at'] ?? null,
            'verified_by_user_id' => $payload['verified_by_user_id'] ?? null,
            'notes' => $this->nullableText($payload['notes'] ?? null),
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeCode(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function normalizeNullableCode(mixed $value): ?string
    {
        $normalized = $this->normalizeCode($value);

        return $normalized === '' ? null : $normalized;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    private function trackedFields(array $record): array
    {
        return array_intersect_key($record, array_flip([
            'tenant_id',
            'facility_id',
            'patient_id',
            'billing_payer_contract_id',
            'insurance_type',
            'insurance_provider',
            'provider_code',
            'plan_name',
            'policy_number',
            'member_id',
            'card_number',
            'effective_date',
            'expiry_date',
            'coverage_level',
            'copay_percent',
            'coverage_limit_amount',
            'status',
            'verification_status',
            'verification_reference',
            'last_verified_at',
        ]));
    }
}

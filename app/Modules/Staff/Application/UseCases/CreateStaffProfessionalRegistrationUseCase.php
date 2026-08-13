<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Application\Exceptions\DuplicateStaffProfessionalRegistrationException;
use App\Modules\Staff\Application\Exceptions\InvalidStaffCredentialingDocumentAssignmentException;
use App\Modules\Staff\Application\Services\VerifiedStaffUserEmailGuard;
use App\Modules\Staff\Domain\Repositories\StaffCredentialingAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffDocumentRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfessionalRegistrationRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffRegulatoryProfileRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\StaffProfessionalRegistrationVerificationStatus;

class CreateStaffProfessionalRegistrationUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffRegulatoryProfileRepositoryInterface $staffRegulatoryProfileRepository,
        private readonly StaffProfessionalRegistrationRepositoryInterface $staffProfessionalRegistrationRepository,
        private readonly StaffDocumentRepositoryInterface $staffDocumentRepository,
        private readonly StaffCredentialingAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly VerifiedStaffUserEmailGuard $verifiedStaffUserEmailGuard,
    ) {}

    public function execute(string $staffProfileId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }
        $this->verifiedStaffUserEmailGuard->assertVerified($profile);

        $tenantId = $this->resolveTenantId($profile);
        $regulatoryProfile = $this->staffRegulatoryProfileRepository->findByStaffProfileId($staffProfileId);
        $regulatorCode = $this->normalizeCode($payload['regulator_code'] ?? null);
        $registrationNumber = trim((string) ($payload['registration_number'] ?? ''));

        if ($this->staffProfessionalRegistrationRepository->existsDuplicateForStaffProfile(
            staffProfileId: $staffProfileId,
            regulatorCode: $regulatorCode,
            registrationNumber: $registrationNumber,
        )) {
            throw new DuplicateStaffProfessionalRegistrationException(
                'Registration number already exists for this staff member and regulator.',
            );
        }

        $sourceDocumentId = $this->resolveSourceDocumentId(
            staffProfileId: $staffProfileId,
            sourceDocumentId: $payload['source_document_id'] ?? null,
        );

        $created = $this->staffProfessionalRegistrationRepository->create([
            'staff_profile_id' => $staffProfileId,
            'tenant_id' => $tenantId,
            'staff_regulatory_profile_id' => $regulatoryProfile['id'] ?? null,
            'regulator_code' => $regulatorCode,
            'registration_category' => trim((string) ($payload['registration_category'] ?? '')),
            'registration_number' => $registrationNumber,
            'license_number' => $this->nullableTrimmedValue($payload['license_number'] ?? null),
            'registration_status' => $this->normalizeCode($payload['registration_status'] ?? null),
            'license_status' => $this->normalizeCode($payload['license_status'] ?? null),
            'verification_status' => StaffProfessionalRegistrationVerificationStatus::PENDING->value,
            'verification_reason' => null,
            'verification_notes' => null,
            'verified_at' => null,
            'verified_by_user_id' => null,
            'issued_at' => $payload['issued_at'] ?? null,
            'expires_at' => $payload['expires_at'] ?? null,
            'renewal_due_at' => $payload['renewal_due_at'] ?? null,
            'cpd_cycle_start_at' => $payload['cpd_cycle_start_at'] ?? null,
            'cpd_cycle_end_at' => $payload['cpd_cycle_end_at'] ?? null,
            'cpd_points_required' => $this->nullableInteger($payload['cpd_points_required'] ?? null),
            'cpd_points_earned' => $this->nullableInteger($payload['cpd_points_earned'] ?? null),
            'source_document_id' => $sourceDocumentId,
            'source_system' => $this->nullableTrimmedValue($payload['source_system'] ?? null),
            'notes' => $this->nullableTrimmedValue($payload['notes'] ?? null),
            'created_by_user_id' => $actorId,
            'updated_by_user_id' => $actorId,
        ]);

        $this->auditLogRepository->write(
            staffProfileId: $staffProfileId,
            tenantId: $tenantId,
            staffRegulatoryProfileId: $regulatoryProfile['id'] ?? null,
            staffProfessionalRegistrationId: (string) ($created['id'] ?? ''),
            action: 'staff-credentialing.registration.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
        );

        return $created;
    }

    private function normalizeCode(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function resolveTenantId(array $profile): ?string
    {
        $profileTenantId = $profile['tenant_id'] ?? null;
        if (is_string($profileTenantId) && trim($profileTenantId) !== '') {
            return trim($profileTenantId);
        }

        return $this->platformScopeContext->tenantId();
    }

    private function resolveSourceDocumentId(string $staffProfileId, mixed $sourceDocumentId): ?string
    {
        $normalized = $this->nullableTrimmedValue($sourceDocumentId);
        if ($normalized === null) {
            return null;
        }

        $document = $this->staffDocumentRepository->findByIdForStaffProfile(
            staffProfileId: $staffProfileId,
            id: $normalized,
        );
        if ($document === null) {
            throw new InvalidStaffCredentialingDocumentAssignmentException(
                'Selected source document does not belong to this staff member.',
            );
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $registration): array
    {
        $tracked = [
            'staff_profile_id',
            'tenant_id',
            'staff_regulatory_profile_id',
            'regulator_code',
            'registration_category',
            'registration_number',
            'license_number',
            'registration_status',
            'license_status',
            'verification_status',
            'issued_at',
            'expires_at',
            'renewal_due_at',
            'cpd_cycle_start_at',
            'cpd_cycle_end_at',
            'cpd_points_required',
            'cpd_points_earned',
            'source_document_id',
            'source_system',
            'notes',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $registration[$field] ?? null;
        }

        return $result;
    }
}

<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Application\Services\VerifiedStaffUserEmailGuard;
use App\Modules\Staff\Application\Exceptions\DuplicateStaffProfessionalRegistrationException;
use App\Modules\Staff\Application\Exceptions\InvalidStaffCredentialingDocumentAssignmentException;
use App\Modules\Staff\Domain\Repositories\StaffCredentialingAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffDocumentRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfessionalRegistrationRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffRegulatoryProfileRepositoryInterface;

class UpdateStaffProfessionalRegistrationUseCase
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

    public function execute(
        string $staffProfileId,
        string $staffProfessionalRegistrationId,
        array $payload,
        ?int $actorId = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }
        $this->verifiedStaffUserEmailGuard->assertVerified($profile);

        $existing = $this->staffProfessionalRegistrationRepository->findByIdForStaffProfile(
            staffProfileId: $staffProfileId,
            id: $staffProfessionalRegistrationId,
        );
        if (! $existing) {
            return null;
        }

        $tenantId = $this->resolveTenantId($profile);
        $regulatoryProfile = $this->staffRegulatoryProfileRepository->findByStaffProfileId($staffProfileId);
        $updatePayload = [];

        if (array_key_exists('regulator_code', $payload)) {
            $updatePayload['regulator_code'] = $this->normalizeCode($payload['regulator_code']);
        }
        if (array_key_exists('registration_category', $payload)) {
            $updatePayload['registration_category'] = trim((string) $payload['registration_category']);
        }
        if (array_key_exists('registration_number', $payload)) {
            $updatePayload['registration_number'] = trim((string) $payload['registration_number']);
        }
        if (array_key_exists('license_number', $payload)) {
            $updatePayload['license_number'] = $this->nullableTrimmedValue($payload['license_number']);
        }
        if (array_key_exists('registration_status', $payload)) {
            $updatePayload['registration_status'] = $this->normalizeCode($payload['registration_status']);
        }
        if (array_key_exists('license_status', $payload)) {
            $updatePayload['license_status'] = $this->normalizeCode($payload['license_status']);
        }
        if (array_key_exists('issued_at', $payload)) {
            $updatePayload['issued_at'] = $payload['issued_at'];
        }
        if (array_key_exists('expires_at', $payload)) {
            $updatePayload['expires_at'] = $payload['expires_at'];
        }
        if (array_key_exists('renewal_due_at', $payload)) {
            $updatePayload['renewal_due_at'] = $payload['renewal_due_at'];
        }
        if (array_key_exists('cpd_cycle_start_at', $payload)) {
            $updatePayload['cpd_cycle_start_at'] = $payload['cpd_cycle_start_at'];
        }
        if (array_key_exists('cpd_cycle_end_at', $payload)) {
            $updatePayload['cpd_cycle_end_at'] = $payload['cpd_cycle_end_at'];
        }
        if (array_key_exists('cpd_points_required', $payload)) {
            $updatePayload['cpd_points_required'] = $this->nullableInteger($payload['cpd_points_required']);
        }
        if (array_key_exists('cpd_points_earned', $payload)) {
            $updatePayload['cpd_points_earned'] = $this->nullableInteger($payload['cpd_points_earned']);
        }
        if (array_key_exists('source_document_id', $payload)) {
            $updatePayload['source_document_id'] = $this->resolveSourceDocumentId(
                staffProfileId: $staffProfileId,
                sourceDocumentId: $payload['source_document_id'],
            );
        }
        if (array_key_exists('source_system', $payload)) {
            $updatePayload['source_system'] = $this->nullableTrimmedValue($payload['source_system']);
        }
        if (array_key_exists('notes', $payload)) {
            $updatePayload['notes'] = $this->nullableTrimmedValue($payload['notes']);
        }

        $nextRegulatorCode = (string) ($updatePayload['regulator_code'] ?? ($existing['regulator_code'] ?? ''));
        $nextRegistrationNumber = (string) ($updatePayload['registration_number'] ?? ($existing['registration_number'] ?? ''));
        if ($this->staffProfessionalRegistrationRepository->existsDuplicateForStaffProfile(
            staffProfileId: $staffProfileId,
            regulatorCode: $nextRegulatorCode,
            registrationNumber: $nextRegistrationNumber,
            excludeId: $staffProfessionalRegistrationId,
        )) {
            throw new DuplicateStaffProfessionalRegistrationException(
                'Registration number already exists for this staff member and regulator.',
            );
        }

        $updatePayload['staff_regulatory_profile_id'] = $regulatoryProfile['id'] ?? ($existing['staff_regulatory_profile_id'] ?? null);
        $updatePayload['updated_by_user_id'] = $actorId;

        $updated = $this->staffProfessionalRegistrationRepository->update(
            $staffProfessionalRegistrationId,
            $updatePayload,
        );
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                staffProfileId: $staffProfileId,
                tenantId: $tenantId,
                staffRegulatoryProfileId: $updated['staff_regulatory_profile_id'] ?? null,
                staffProfessionalRegistrationId: $staffProfessionalRegistrationId,
                action: 'staff-credentialing.registration.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
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
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'staff_regulatory_profile_id',
            'regulator_code',
            'registration_category',
            'registration_number',
            'license_number',
            'registration_status',
            'license_status',
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

        $changes = [];
        foreach ($trackedFields as $field) {
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
}

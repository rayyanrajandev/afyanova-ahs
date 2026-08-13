<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\StaffProfessionalRegistrationRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\StaffProfessionalLicenseStatus;
use App\Modules\Staff\Domain\ValueObjects\StaffProfessionalRegistrationStatus;
use App\Modules\Staff\Domain\ValueObjects\StaffProfessionalRegistrationVerificationStatus;
use App\Modules\Staff\Domain\ValueObjects\StaffRegulatorCode;

class ListStaffProfessionalRegistrationsUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffProfessionalRegistrationRepositoryInterface $staffProfessionalRegistrationRepository,
    ) {}

    public function execute(string $staffProfileId, array $filters): ?array
    {
        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $regulatorCode = isset($filters['regulatorCode']) ? strtolower(trim((string) $filters['regulatorCode'])) : null;
        if (! in_array($regulatorCode, StaffRegulatorCode::values(), true)) {
            $regulatorCode = null;
        }

        $registrationStatus = isset($filters['registrationStatus']) ? strtolower(trim((string) $filters['registrationStatus'])) : null;
        if (! in_array($registrationStatus, StaffProfessionalRegistrationStatus::values(), true)) {
            $registrationStatus = null;
        }

        $licenseStatus = isset($filters['licenseStatus']) ? strtolower(trim((string) $filters['licenseStatus'])) : null;
        if (! in_array($licenseStatus, StaffProfessionalLicenseStatus::values(), true)) {
            $licenseStatus = null;
        }

        $verificationStatus = isset($filters['verificationStatus']) ? strtolower(trim((string) $filters['verificationStatus'])) : null;
        if (! in_array($verificationStatus, StaffProfessionalRegistrationVerificationStatus::values(), true)) {
            $verificationStatus = null;
        }

        $expiresFrom = isset($filters['expiresFrom']) ? trim((string) $filters['expiresFrom']) : null;
        $expiresFrom = $expiresFrom === '' ? null : $expiresFrom;

        $expiresTo = isset($filters['expiresTo']) ? trim((string) $filters['expiresTo']) : null;
        $expiresTo = $expiresTo === '' ? null : $expiresTo;

        $sortMap = [
            'regulatorCode' => 'regulator_code',
            'registrationCategory' => 'registration_category',
            'registrationNumber' => 'registration_number',
            'licenseNumber' => 'license_number',
            'registrationStatus' => 'registration_status',
            'licenseStatus' => 'license_status',
            'verificationStatus' => 'verification_status',
            'issuedAt' => 'issued_at',
            'expiresAt' => 'expires_at',
            'renewalDueAt' => 'renewal_due_at',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'expiresAt'] ?? 'expires_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        return $this->staffProfessionalRegistrationRepository->searchByStaffProfileId(
            staffProfileId: $staffProfileId,
            regulatorCode: $regulatorCode,
            registrationStatus: $registrationStatus,
            licenseStatus: $licenseStatus,
            verificationStatus: $verificationStatus,
            expiresFrom: $expiresFrom,
            expiresTo: $expiresTo,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}

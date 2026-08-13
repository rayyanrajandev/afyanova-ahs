<?php

namespace App\Modules\Staff\Application\Services;

use App\Modules\Platform\Domain\Repositories\FacilityRepositoryInterface;
use App\Modules\Staff\Application\Exceptions\InvalidClinicalPrivilegeCatalogAssignmentException;
use App\Modules\Staff\Domain\Repositories\StaffRegulatoryProfileRepositoryInterface;

class ClinicalPrivilegeCatalogAssignmentGuard
{
    public function __construct(
        private readonly FacilityRepositoryInterface $facilityRepository,
        private readonly StaffRegulatoryProfileRepositoryInterface $staffRegulatoryProfileRepository,
    ) {}

    public function assertEligible(string $staffProfileId, string $facilityId, array $catalog): void
    {
        $reasons = [];

        $requiredFacilityType = $this->normalizeCode($catalog['facility_type'] ?? null);
        if ($requiredFacilityType !== null) {
            $facility = $this->facilityRepository->findById($facilityId);
            $selectedFacilityType = $this->normalizeCode($facility['facility_type'] ?? null);

            if ($selectedFacilityType === null) {
                $reasons[] = sprintf(
                    'Template requires facility type %s, but the selected facility has no facility type recorded.',
                    $this->formatCode($requiredFacilityType),
                );
            } elseif ($selectedFacilityType !== $requiredFacilityType) {
                $reasons[] = sprintf(
                    'Template requires facility type %s, but the selected facility is %s.',
                    $this->formatCode($requiredFacilityType),
                    $this->formatCode($selectedFacilityType),
                );
            }
        }

        $requiredCadreCode = $this->normalizeCode($catalog['cadre_code'] ?? null);
        if ($requiredCadreCode !== null) {
            $regulatoryProfile = $this->staffRegulatoryProfileRepository->findByStaffProfileId($staffProfileId);
            $staffCadreCode = $this->normalizeCode($regulatoryProfile['cadre_code'] ?? null);

            if ($staffCadreCode === null) {
                $reasons[] = sprintf(
                    'Template requires staff cadre %s, but no staff regulatory cadre is recorded.',
                    $this->formatCode($requiredCadreCode),
                );
            } elseif ($staffCadreCode !== $requiredCadreCode) {
                $reasons[] = sprintf(
                    'Template requires staff cadre %s, but the staff regulatory cadre is %s.',
                    $this->formatCode($requiredCadreCode),
                    $this->formatCode($staffCadreCode),
                );
            }
        }

        if ($reasons === []) {
            return;
        }

        throw new InvalidClinicalPrivilegeCatalogAssignmentException(
            'Selected privilege template is outside the current staff/facility scope. '.implode(' ', $reasons),
        );
    }

    private function normalizeCode(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    private function formatCode(string $value): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $value));
    }
}

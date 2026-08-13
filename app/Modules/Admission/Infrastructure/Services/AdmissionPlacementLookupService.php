<?php

namespace App\Modules\Admission\Infrastructure\Services;

use App\Modules\Admission\Application\Exceptions\InvalidAdmissionPlacementException;
use App\Modules\Admission\Domain\Repositories\AdmissionRepositoryInterface;
use App\Modules\Admission\Domain\Services\AdmissionPlacementLookupServiceInterface;
use App\Modules\Platform\Domain\Repositories\FacilityResourceRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\ValueObjects\FacilityResourceType;

class AdmissionPlacementLookupService implements AdmissionPlacementLookupServiceInterface
{
    public function __construct(
        private readonly AdmissionRepositoryInterface $admissionRepository,
        private readonly FacilityResourceRepositoryInterface $facilityResourceRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
    ) {}

    public function validatePlacement(
        ?string $ward,
        ?string $bed,
        string $wardField = 'ward',
        string $bedField = 'bed',
        ?string $excludeAdmissionId = null,
    ): array {
        $normalizedWard = $this->normalize($ward);
        $normalizedBed = $this->normalize($bed);

        if ($normalizedWard === null && $normalizedBed === null) {
            return [
                'ward' => null,
                'bed' => null,
            ];
        }

        $errors = [];

        if ($normalizedWard === null) {
            $errors[$wardField] = ['Ward is required when selecting a bed.'];
        }

        if ($normalizedBed === null) {
            $errors[$bedField] = ['Bed is required when selecting a ward.'];
        }

        if ($errors !== []) {
            throw new InvalidAdmissionPlacementException(
                'Ward and bed must be provided together.',
                $errors,
            );
        }

        $isValid = $this->facilityResourceRepository->activeWardBedExistsInScope(
            wardName: $normalizedWard,
            bedNumber: $normalizedBed,
            tenantId: $this->platformScopeContext->tenantId(),
            facilityId: $this->platformScopeContext->facilityId(),
        );

        if (! $isValid) {
            $message = 'Selected ward and bed do not match an active ward-bed in the facility registry.';

            throw new InvalidAdmissionPlacementException($message, [
                $wardField => [$message],
                $bedField => [$message],
            ]);
        }

        $hasConflict = $this->admissionRepository->hasActivePlacementConflict(
            ward: $normalizedWard,
            bed: $normalizedBed,
            tenantId: $this->platformScopeContext->tenantId(),
            facilityId: $this->platformScopeContext->facilityId(),
            excludeAdmissionId: $excludeAdmissionId,
        );

        if ($hasConflict) {
            $message = 'Selected ward and bed are already occupied by another active admission.';

            throw new InvalidAdmissionPlacementException($message, [
                $wardField => [$message],
                $bedField => [$message],
            ]);
        }

        return [
            'ward' => $normalizedWard,
            'bed' => $normalizedBed,
        ];
    }

    public function validatePlacementByResource(
        string $bedResourceId,
        ?string $patientGender = null,
        string $fieldName = 'bedResourceId',
        ?string $excludeAdmissionId = null,
    ): array {
        $normalizedId = trim($bedResourceId);
        if ($normalizedId === '') {
            $message = 'A bed must be selected.';

            throw new InvalidAdmissionPlacementException($message, [$fieldName => [$message]]);
        }

        $resource = $this->facilityResourceRepository->findById($normalizedId);
        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();

        $placeableTypes = [FacilityResourceType::WARD_BED->value, FacilityResourceType::OBSERVATION_ROOM->value];

        if (
            $resource === null
            || ! in_array($resource['resource_type'] ?? null, $placeableTypes, true)
            || ($resource['status'] ?? null) !== 'active'
            || ! $this->resourceInScope($resource, $tenantId, $facilityId)
        ) {
            $message = 'Selected bed is not an active bed in this facility.';

            throw new InvalidAdmissionPlacementException($message, [$fieldName => [$message]]);
        }

        $genderRestriction = $this->normalize($resource['gender_restriction'] ?? null);
        if ($genderRestriction !== null && $patientGender !== null && strtolower($patientGender) !== strtolower($genderRestriction)) {
            $message = sprintf('This room is reserved for %s patients.', $genderRestriction);

            throw new InvalidAdmissionPlacementException($message, [$fieldName => [$message]]);
        }

        $hasConflict = $this->admissionRepository->hasActiveBedResourceConflict(
            bedResourceId: $normalizedId,
            tenantId: $tenantId,
            facilityId: $facilityId,
            excludeAdmissionId: $excludeAdmissionId,
        );

        if ($hasConflict) {
            $message = 'Selected bed is already occupied by another active admission.';

            throw new InvalidAdmissionPlacementException($message, [$fieldName => [$message]]);
        }

        return [
            'bed_resource_id' => $normalizedId,
            'ward' => $this->normalize($resource['ward_name'] ?? null),
            'bed' => $this->normalize($resource['bed_number'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function resourceInScope(array $resource, ?string $tenantId, ?string $facilityId): bool
    {
        $resourceTenantId = $resource['tenant_id'] ?? null;
        $resourceFacilityId = $resource['facility_id'] ?? null;

        if ($tenantId !== null && $resourceTenantId !== null && $resourceTenantId !== $tenantId) {
            return false;
        }

        if ($facilityId !== null && $resourceFacilityId !== null && $resourceFacilityId !== $facilityId) {
            return false;
        }

        return true;
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

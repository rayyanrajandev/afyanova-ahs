<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FacilityRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\TenantRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\UserFacilityAssignmentRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ResolvePlatformAccessScopeUseCase
{
    public function __construct(
        private readonly UserFacilityAssignmentRepositoryInterface $userFacilityAssignmentRepository,
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly FacilityRepositoryInterface $facilityRepository,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(int $userId, ?string $tenantCode, ?string $facilityCode, bool $autoSelectSingleFacility = true): array
    {
        $tenantCode = $this->normalizeScopeCode($tenantCode);
        $facilityCode = $this->normalizeScopeCode($facilityCode);

        $assignments = $this->userFacilityAssignmentRepository->listActiveFacilityScopesByUserId($userId);

        $resolved = [
            'tenant' => null,
            'facility' => null,
            'resolvedFrom' => 'none',
            'headers' => [
                'tenantCode' => $tenantCode,
                'facilityCode' => $facilityCode,
            ],
            'userAccess' => [
                'accessibleFacilityCount' => count($assignments),
                'facilities' => array_map(
                    fn (array $assignment): array => $this->toAccessibleFacilitySummary($assignment),
                    $assignments
                ),
            ],
        ];

        if ($tenantCode === null && $facilityCode === null) {
            if ($autoSelectSingleFacility && count($assignments) === 1) {
                $assignment = $assignments[0];
                $resolved['tenant'] = $this->toTenantSummary($assignment);
                $resolved['facility'] = $this->toFacilitySummary($assignment);
                $resolved['resolvedFrom'] = 'single_assignment';

                return $resolved;
            }

            // No explicit scope — fall back to the user's primary facility so
            // multi-facility accounts (incl. super-admins) resolve a scope
            // instead of 403ing on every scoped API call until they opt into
            // a facility via cookie/header.
            if ($autoSelectSingleFacility && count($assignments) > 1) {
                $primaryAssignments = array_values(array_filter(
                    $assignments,
                    static fn (array $assignment): bool => (bool) ($assignment['is_primary'] ?? false),
                ));

                if (count($primaryAssignments) === 1) {
                    $assignment = $primaryAssignments[0];
                    $resolved['tenant'] = $this->toTenantSummary($assignment);
                    $resolved['facility'] = $this->toFacilitySummary($assignment);
                    $resolved['resolvedFrom'] = 'primary_assignment';

                    return $resolved;
                }
            }

            return $resolved;
        }

        $tenant = null;
        if ($tenantCode !== null) {
            $tenant = $this->tenantRepository->findByCode($tenantCode);
            if (! $tenant) {
                throw new NotFoundHttpException('Tenant not found.');
            }

            $resolved['tenant'] = [
                'id' => $tenant['id'] ?? null,
                'code' => $tenant['code'] ?? null,
                'name' => $tenant['name'] ?? null,
                'countryCode' => $tenant['country_code'] ?? null,
                'status' => $tenant['status'] ?? null,
            ];
        }

        if ($facilityCode !== null) {
            $tenantId = $tenant['id'] ?? null;
            $facility = $this->facilityRepository->findByCode($facilityCode, $tenantId);
            if (! $facility) {
                throw new NotFoundHttpException('Facility not found.');
            }

            $matchedAssignment = collect($assignments)->first(function (array $assignment) use ($facility): bool {
                return ($assignment['facility_id'] ?? null) === ($facility['id'] ?? null);
            });

            if (! is_array($matchedAssignment)) {
                throw new AccessDeniedHttpException('User has no access to requested facility.');
            }

            $resolved['tenant'] = $this->toTenantSummary($matchedAssignment);
            $resolved['facility'] = $this->toFacilitySummary($matchedAssignment);
            $resolved['resolvedFrom'] = 'headers';

            return $resolved;
        }

        $tenantAssignments = array_values(array_filter(
            $assignments,
            static fn (array $assignment): bool => ($assignment['tenant_code'] ?? null) === $tenantCode
        ));

        if ($tenantAssignments === []) {
            throw new AccessDeniedHttpException('User has no access to requested tenant.');
        }

        if (count($tenantAssignments) === 1) {
            $resolved['tenant'] = $this->toTenantSummary($tenantAssignments[0]);
            $resolved['facility'] = $this->toFacilitySummary($tenantAssignments[0]);
            $resolved['resolvedFrom'] = 'tenant_header_single_facility';

            return $resolved;
        }

        $resolved['tenant'] = $this->toTenantSummary($tenantAssignments[0]);
        $resolved['resolvedFrom'] = 'tenant_header';

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @return array<string, mixed>
     */
    private function toTenantSummary(array $assignment): array
    {
        return [
            'id' => $assignment['tenant_id'] ?? null,
            'code' => $assignment['tenant_code'] ?? null,
            'name' => $assignment['tenant_name'] ?? null,
            'countryCode' => $assignment['tenant_country_code'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @return array<string, mixed>
     */
    private function toFacilitySummary(array $assignment): array
    {
        return [
            'id' => $assignment['facility_id'] ?? null,
            'code' => $assignment['facility_code'] ?? null,
            'name' => $assignment['facility_name'] ?? null,
            'facilityType' => $assignment['facility_type'] ?? null,
            'timezone' => $assignment['facility_timezone'] ?? null,
            'isPrimary' => (bool) ($assignment['is_primary'] ?? false),
            'assignmentRole' => $assignment['assignment_role'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @return array<string, mixed>
     */
    private function toAccessibleFacilitySummary(array $assignment): array
    {
        return [
            ...$this->toFacilitySummary($assignment),
            'tenantId' => $assignment['tenant_id'] ?? null,
            'tenantCode' => $assignment['tenant_code'] ?? null,
            'tenantName' => $assignment['tenant_name'] ?? null,
        ];
    }

    private function normalizeScopeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $normalized = strtoupper(trim($code));

        return $normalized !== '' ? $normalized : null;
    }
}

<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Application\Exceptions\InvalidStaffSpecialtyAssignmentsException;
use App\Modules\Staff\Application\Exceptions\UnknownClinicalSpecialtyException;
use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;

class SyncStaffProfileSpecialtiesUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly ClinicalSpecialtyRepositoryInterface $clinicalSpecialtyRepository,
        private readonly ClinicalSpecialtyAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $assignments
     * @return array<int, array<string, mixed>>|null
     */
    public function execute(string $staffProfileId, array $assignments, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }

        $normalizedAssignments = $this->normalizeAssignments($assignments);
        $specialtyIds = array_values(array_map(
            static fn (array $assignment): string => (string) ($assignment['specialty_id'] ?? ''),
            $normalizedAssignments,
        ));

        $resolvedSpecialtyIds = $this->clinicalSpecialtyRepository->resolveExistingSpecialtyIdsInScope($specialtyIds);
        if (count($resolvedSpecialtyIds) !== count($specialtyIds)) {
            throw new UnknownClinicalSpecialtyException('One or more specialties are invalid or outside the current scope.');
        }

        $primaryCount = count(array_filter(
            $normalizedAssignments,
            static fn (array $assignment): bool => (bool) ($assignment['is_primary'] ?? false),
        ));
        if ($primaryCount > 1) {
            throw new InvalidStaffSpecialtyAssignmentsException('Only one primary specialty is allowed per staff profile.');
        }

        $before = $this->clinicalSpecialtyRepository->listByStaffProfileId($staffProfileId);
        $after = $this->clinicalSpecialtyRepository->syncStaffProfileSpecialties(
            staffProfileId: $staffProfileId,
            assignments: $normalizedAssignments,
        );

        $beforeBySpecialtyId = $this->indexBySpecialtyId($before);
        $afterBySpecialtyId = $this->indexBySpecialtyId($after);
        $affectedSpecialtyIds = array_values(array_unique(array_merge(
            array_keys($beforeBySpecialtyId),
            array_keys($afterBySpecialtyId),
        )));

        foreach ($affectedSpecialtyIds as $specialtyId) {
            $beforeValue = $beforeBySpecialtyId[$specialtyId] ?? null;
            $afterValue = $afterBySpecialtyId[$specialtyId] ?? null;
            if ($beforeValue === $afterValue) {
                continue;
            }

            $this->auditLogRepository->write(
                specialtyId: $specialtyId,
                tenantId: $this->platformScopeContext->tenantId(),
                staffProfileId: $staffProfileId,
                action: 'staff-specialty.assignment.synced',
                actorId: $actorId,
                changes: [
                    'assignment' => [
                        'before' => $beforeValue,
                        'after' => $afterValue,
                    ],
                ],
                metadata: [
                    'staffProfileId' => $staffProfileId,
                ],
            );
        }

        return $after;
    }

    /**
     * @param  array<int, array<string, mixed>>  $assignments
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAssignments(array $assignments): array
    {
        $bySpecialtyId = [];
        foreach ($assignments as $assignment) {
            $specialtyId = trim((string) ($assignment['specialty_id'] ?? ''));
            if ($specialtyId === '') {
                continue;
            }

            $bySpecialtyId[$specialtyId] = [
                'specialty_id' => $specialtyId,
                'is_primary' => (bool) ($assignment['is_primary'] ?? false),
            ];
        }

        return array_values($bySpecialtyId);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexBySpecialtyId(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $specialtyId = (string) ($row['specialty_id'] ?? $row['specialtyId'] ?? '');
            if ($specialtyId === '') {
                continue;
            }

            $indexed[$specialtyId] = $row;
        }

        return $indexed;
    }
}


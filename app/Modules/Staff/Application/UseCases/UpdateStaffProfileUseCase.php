<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Application\Exceptions\DuplicateStaffProfileForUserException;
use App\Modules\Staff\Application\Exceptions\UserNotEligibleForStaffProfileException;
use App\Modules\Staff\Domain\Repositories\StaffProfileAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\Services\UserLookupServiceInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateStaffProfileUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffProfileAuditLogRepositoryInterface $auditLogRepository,
        private readonly UserLookupServiceInterface $userLookupService,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->staffProfileRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $userId = (int) ($payload['user_id'] ?? $existing['user_id']);
        if (! $this->userLookupService->userExists($userId)) {
            throw new UserNotEligibleForStaffProfileException(
                'Staff profile can only be assigned to an existing user.',
            );
        }

        if (array_key_exists('user_id', $payload)) {
            $existingForUser = $this->staffProfileRepository->findByUserId((string) $userId);
            if ($existingForUser !== null && ($existingForUser['id'] ?? null) !== $id) {
                throw new DuplicateStaffProfileForUserException(
                    'A staff profile already exists for the selected user.',
                );
            }
        }

        $updated = $this->staffProfileRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                staffProfileId: $id,
                action: 'staff-profile.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'user_id',
            'employee_number',
            'department',
            'job_title',
            'professional_license_number',
            'license_type',
            'phone_extension',
            'employment_type',
            'status',
            'status_reason',
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

<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Application\Exceptions\DuplicateStaffProfileForUserException;
use App\Modules\Staff\Application\Exceptions\UserNotEligibleForStaffProfileException;
use App\Modules\Staff\Domain\Repositories\StaffProfileAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\Services\UserLookupServiceInterface;
use App\Modules\Staff\Domain\ValueObjects\StaffProfileStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Str;
use RuntimeException;

class CreateStaffProfileUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffProfileAuditLogRepositoryInterface $auditLogRepository,
        private readonly UserLookupServiceInterface $userLookupService,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $userId = (int) $payload['user_id'];
        if ($this->userLookupService->findEligibleUserById($userId) === null) {
            throw new UserNotEligibleForStaffProfileException(
                'Select an active user from search. This user may already be linked, inactive, or outside your current scope.',
            );
        }

        if ($this->staffProfileRepository->findByUserId((string) $userId) !== null) {
            throw new DuplicateStaffProfileForUserException(
                'A staff profile already exists for the selected user.',
            );
        }

        $payload['status'] = StaffProfileStatus::ACTIVE->value;
        $payload['employee_number'] = $this->generateEmployeeNumber();
        $payload['tenant_id'] = $this->platformScopeContext->tenantId();

        $created = $this->staffProfileRepository->create($payload);

        $this->auditLogRepository->write(
            staffProfileId: $created['id'],
            action: 'staff-profile.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
        );

        return $created;
    }

    private function generateEmployeeNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'STF'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->staffProfileRepository->existsByEmployeeNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique employee number.');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $profile): array
    {
        $tracked = [
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

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $profile[$field] ?? null;
        }

        return $result;
    }
}

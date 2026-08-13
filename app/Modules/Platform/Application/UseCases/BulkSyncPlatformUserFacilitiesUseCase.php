<?php

namespace App\Modules\Platform\Application\UseCases;

use Illuminate\Support\Facades\DB;

class BulkSyncPlatformUserFacilitiesUseCase
{
    public function __construct(
        private readonly SyncPlatformUserFacilitiesUseCase $syncPlatformUserFacilitiesUseCase,
    ) {}

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, array<string, mixed>>  $facilityAssignments
     * @return array<string, mixed>
     */
    public function execute(
        array $userIds,
        array $facilityAssignments,
        ?string $approvalCaseReference = null,
        ?int $actorId = null
    ): array
    {
        $normalizedUserIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): int => (int) $value,
            $userIds,
        ), static fn (int $value): bool => $value > 0)));

        [$users, $skippedUserIds] = DB::transaction(function () use (
            $normalizedUserIds,
            $facilityAssignments,
            $approvalCaseReference,
            $actorId
        ): array {
            $users = [];
            $skippedUserIds = [];

            foreach ($normalizedUserIds as $userId) {
                $result = $this->syncPlatformUserFacilitiesUseCase->execute(
                    userId: $userId,
                    facilityAssignments: $facilityAssignments,
                    approvalCaseReference: $approvalCaseReference,
                    actorId: $actorId,
                );

                if ($result === null) {
                    $skippedUserIds[] = $userId;

                    continue;
                }

                $users[] = $result;
            }

            return [$users, $skippedUserIds];
        });

        return [
            'requested_count' => count($normalizedUserIds),
            'updated_count' => count($users),
            'skipped_user_ids' => $skippedUserIds,
            'users' => $users,
        ];
    }
}

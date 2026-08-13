<?php

namespace App\Modules\Platform\Application\UseCases;

use Illuminate\Support\Facades\DB;

class BulkSyncPlatformUserRolesUseCase
{
    public function __construct(
        private readonly SyncPlatformUserRolesUseCase $syncPlatformUserRolesUseCase,
    ) {}

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, string>  $roleIds
     * @return array<string, mixed>
     */
    public function execute(
        array $userIds,
        array $roleIds,
        ?string $approvalCaseReference = null,
        ?int $actorId = null
    ): array {
        $normalizedUserIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): int => (int) $value,
            $userIds,
        ), static fn (int $value): bool => $value > 0)));

        $normalizedRoleIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => is_string($value) ? trim($value) : '',
            $roleIds,
        ))));

        [$updates, $skippedUserIds] = DB::transaction(function () use (
            $normalizedUserIds,
            $normalizedRoleIds,
            $approvalCaseReference,
            $actorId
        ): array {
            $updates = [];
            $skippedUserIds = [];

            foreach ($normalizedUserIds as $userId) {
                $result = $this->syncPlatformUserRolesUseCase->execute(
                    userId: $userId,
                    roleIds: $normalizedRoleIds,
                    approvalCaseReference: $approvalCaseReference,
                    actorId: $actorId,
                );

                if ($result === null) {
                    $skippedUserIds[] = $userId;

                    continue;
                }

                $updates[] = $result;
            }

            return [$updates, $skippedUserIds];
        });

        return [
            'requested_count' => count($normalizedUserIds),
            'updated_count' => count($updates),
            'skipped_user_ids' => $skippedUserIds,
            'updates' => $updates,
        ];
    }
}

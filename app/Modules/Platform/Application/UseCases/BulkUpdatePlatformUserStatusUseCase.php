<?php

namespace App\Modules\Platform\Application\UseCases;

use Illuminate\Support\Facades\DB;

class BulkUpdatePlatformUserStatusUseCase
{
    public function __construct(private readonly UpdatePlatformUserStatusUseCase $updatePlatformUserStatusUseCase) {}

    /**
     * @param  array<int, int>  $userIds
     * @return array<string, mixed>
     */
    public function execute(
        array $userIds,
        string $status,
        ?string $reason = null,
        ?string $approvalCaseReference = null,
        ?int $actorId = null
    ): array {
        $normalizedUserIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): int => (int) $value,
            $userIds,
        ), static fn (int $value): bool => $value > 0)));

        [$updatedUsers, $skippedUserIds] = DB::transaction(function () use (
            $normalizedUserIds,
            $status,
            $reason,
            $approvalCaseReference,
            $actorId
        ): array {
            $updatedUsers = [];
            $skippedUserIds = [];

            foreach ($normalizedUserIds as $userId) {
                $updated = $this->updatePlatformUserStatusUseCase->execute(
                    id: $userId,
                    status: $status,
                    reason: $reason,
                    approvalCaseReference: $approvalCaseReference,
                    actorId: $actorId,
                );

                if ($updated === null) {
                    $skippedUserIds[] = $userId;

                    continue;
                }

                $updatedUsers[] = $updated;
            }

            return [$updatedUsers, $skippedUserIds];
        });

        return [
            'requested_count' => count($normalizedUserIds),
            'updated_count' => count($updatedUsers),
            'skipped_user_ids' => $skippedUserIds,
            'users' => $updatedUsers,
        ];
    }
}

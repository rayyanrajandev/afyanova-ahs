<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Application\Exceptions\PasswordResetDispatchFailedException;
use App\Modules\Platform\Domain\Repositories\PlatformUserAdminRepositoryInterface;

class BulkDispatchPlatformUserCredentialLinksUseCase
{
    public function __construct(
        private readonly PlatformUserAdminRepositoryInterface $platformUserAdminRepository,
        private readonly SendPlatformUserInviteLinkUseCase $sendPlatformUserInviteLinkUseCase,
        private readonly SendPlatformUserPasswordResetLinkUseCase $sendPlatformUserPasswordResetLinkUseCase,
    ) {}

    /**
     * @param  array<int, int>  $userIds
     * @return array<string, mixed>
     */
    public function execute(array $userIds, ?int $actorId = null): array
    {
        $normalizedUserIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): int => (int) $value,
            $userIds,
        ), static fn (int $value): bool => $value > 0)));

        $inviteCount = 0;
        $resetCount = 0;
        $skippedUserIds = [];
        $failed = [];

        foreach ($normalizedUserIds as $userId) {
            $user = $this->platformUserAdminRepository->findUserById($userId);
            if ($user === null) {
                $skippedUserIds[] = $userId;

                continue;
            }

            try {
                if (($user['email_verified_at'] ?? null) === null) {
                    $this->sendPlatformUserInviteLinkUseCase->execute(
                        userId: $userId,
                        actorId: $actorId,
                    );
                    $inviteCount++;
                } else {
                    $this->sendPlatformUserPasswordResetLinkUseCase->execute(
                        userId: $userId,
                        actorId: $actorId,
                    );
                    $resetCount++;
                }
            } catch (PasswordResetDispatchFailedException $exception) {
                $failed[] = [
                    'user_id' => $userId,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $failedUserIds = array_values(array_map(
            static fn (array $failure): int => (int) ($failure['user_id'] ?? 0),
            $failed,
        ));

        return [
            'requested_count' => count($normalizedUserIds),
            'dispatched_count' => $inviteCount + $resetCount,
            'invite_count' => $inviteCount,
            'reset_count' => $resetCount,
            'skipped_user_ids' => $skippedUserIds,
            'failed_count' => count($failedUserIds),
            'failed_user_ids' => $failedUserIds,
            'failed' => $failed,
        ];
    }
}

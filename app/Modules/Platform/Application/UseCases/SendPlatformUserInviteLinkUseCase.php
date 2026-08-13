<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Models\User;
use App\Modules\Platform\Application\Exceptions\PasswordResetDispatchFailedException;
use App\Modules\Platform\Application\Support\CredentialLinkDeliveryPolicy;
use App\Modules\Platform\Domain\Repositories\PlatformUserAdminRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Facades\Password;

class SendPlatformUserInviteLinkUseCase
{
    public function __construct(
        private readonly PlatformUserAdminRepositoryInterface $platformUserAdminRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly CredentialLinkDeliveryPolicy $credentialLinkDeliveryPolicy,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(int $userId, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $user = $this->platformUserAdminRepository->findUserById($userId);
        if (! $user) {
            return null;
        }

        if (($user['email_verified_at'] ?? null) !== null) {
            throw new PasswordResetDispatchFailedException('User already has a verified email; use reset password link instead.');
        }

        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '') {
            throw new PasswordResetDispatchFailedException('User email is missing.');
        }

        $previewUrl = null;
        if ($this->credentialLinkDeliveryPolicy->shouldReturnLocalPreview()) {
            $userModel = User::query()->find($userId);
            if (! $userModel) {
                return null;
            }

            $token = Password::broker()->createToken($userModel);
            $previewUrl = url('/reset-password/'.$token).'?email='.urlencode($email);
            $status = Password::RESET_LINK_SENT;
        } else {
            $status = Password::broker()->sendResetLink([
                'email' => $email,
            ]);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            throw new PasswordResetDispatchFailedException('Failed to dispatch invite link.');
        }

        $this->platformUserAdminRepository->writeAuditLog(
            tenantId: $this->platformScopeContext->tenantId(),
            facilityId: $this->platformScopeContext->facilityId(),
            actorId: $actorId,
            targetUserId: $userId,
            action: 'platform-user.invite-link.sent',
            metadata: [
                'email' => $email,
                'delivery_mode' => $previewUrl !== null ? 'local-preview' : 'email',
            ],
        );

        return [
            'user_id' => $userId,
            'message' => __($status),
            'preview_url' => $previewUrl,
            'delivery_mode' => $previewUrl !== null ? 'local-preview' : 'email',
        ];
    }
}

<?php

namespace App\Modules\Platform\Application\Support;

use App\Models\User;
use App\Modules\Platform\Application\Exceptions\DuplicatePlatformUserEmailException;
use App\Modules\Platform\Application\Exceptions\PasswordResetDispatchFailedException;
use App\Modules\Platform\Domain\Repositories\PlatformUserAdminRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\PlatformUserStatus;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use DomainException;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class FacilityAdminProvisioningService
{
    public function __construct(
        private readonly FacilityAdminEligibilityPolicy $eligibilityPolicy,
        private readonly PlatformUserAdminRepositoryInterface $platformUserAdminRepository,
        private readonly CredentialLinkDeliveryPolicy $credentialLinkDeliveryPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createFacilityAdmin(array $payload, string $tenantId, ?int $actorId = null): User
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));

        if ($this->platformUserAdminRepository->emailExists($email)) {
            throw new DuplicatePlatformUserEmailException('User email already exists.');
        }

        $role = $this->facilityAdminRole();
        if (! $role) {
            throw new DomainException('Facility Administrator role is not configured.');
        }

        $user = User::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => $email,
            'password' => Str::password(24),
            'status' => PlatformUserStatus::ACTIVE->value,
            'status_reason' => null,
            'deactivated_at' => null,
        ]);

        $user->roles()->syncWithoutDetaching([$role->id]);
        $user->load(['roles:id,code,name,status']);

        $this->platformUserAdminRepository->writeAuditLog(
            tenantId: $tenantId,
            facilityId: null,
            actorId: $actorId,
            targetUserId: (int) $user->id,
            action: 'platform-user.facility-admin.created',
            changes: [
                'after' => [
                    'id' => $user->id,
                    'tenant_id' => $tenantId,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'role_code' => $role->code,
                ],
            ],
            metadata: [
                'role_id' => $role->id,
                'role_code' => $role->code,
                'source' => 'facility_provisioning',
            ],
        );

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function dispatchInviteLink(User $user, string $tenantId, string $facilityId, ?int $actorId = null): array
    {
        $email = strtolower(trim((string) $user->email));
        if ($email === '') {
            throw new PasswordResetDispatchFailedException('Facility admin email is missing.');
        }

        if ($user->email_verified_at !== null) {
            throw new PasswordResetDispatchFailedException('Facility admin already has a verified email; use reset password instead.');
        }

        $previewUrl = null;
        if ($this->credentialLinkDeliveryPolicy->shouldReturnLocalPreview()) {
            $token = Password::broker()->createToken($user);
            $previewUrl = url('/reset-password/'.$token).'?email='.urlencode($email);
            $status = Password::RESET_LINK_SENT;
        } else {
            $status = Password::broker()->sendResetLink([
                'email' => $email,
            ]);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            throw new PasswordResetDispatchFailedException('Failed to dispatch facility admin invite link.');
        }

        $this->platformUserAdminRepository->writeAuditLog(
            tenantId: $tenantId,
            facilityId: $facilityId,
            actorId: $actorId,
            targetUserId: (int) $user->id,
            action: 'platform-user.invite-link.sent',
            metadata: [
                'email' => $email,
                'delivery_mode' => $previewUrl !== null ? 'local-preview' : 'email',
                'source' => 'facility_provisioning',
            ],
        );

        return [
            'user_id' => (int) $user->id,
            'message' => __($status),
            'preview_url' => $previewUrl,
            'delivery_mode' => $previewUrl !== null ? 'local-preview' : 'email',
        ];
    }

    private function facilityAdminRole(): ?RoleModel
    {
        return RoleModel::query()
            ->whereIn('code', $this->eligibilityPolicy->eligibleRoleCodes())
            ->where('status', 'active')
            ->orderBy('name')
            ->first();
    }
}

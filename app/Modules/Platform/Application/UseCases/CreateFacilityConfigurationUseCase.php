<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Models\User;
use App\Modules\Platform\Application\Exceptions\DuplicateFacilityCodeException;
use App\Modules\Platform\Application\Support\FacilityAdminEligibilityPolicy;
use App\Modules\Platform\Application\Support\FacilityAdminProvisioningService;
use App\Modules\Platform\Domain\Repositories\FacilityConfigurationAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FacilityConfigurationRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\TenantRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class CreateFacilityConfigurationUseCase
{
    public function __construct(
        private readonly FacilityConfigurationRepositoryInterface $facilityConfigurationRepository,
        private readonly FacilityConfigurationAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly FacilityAdminEligibilityPolicy $facilityAdminEligibilityPolicy,
        private readonly FacilityAdminProvisioningService $facilityAdminProvisioningService,
    ) {}

    /**
     * @return array{
     *     facility: array<string, mixed>,
     *     facility_admin_user_id: int|null,
     *     created_facility_admin_user_id: int|null,
     *     facility_admin_invite: array<string, mixed>|null,
     *     facility_admin_invite_error: string|null
     * }
     */
    public function execute(array $payload, ?int $actorId = null): array
    {
        $result = DB::transaction(function () use ($payload, $actorId): array {
            $tenantCode = $this->normalizeCode((string) ($payload['tenant_code'] ?? ''));
            $facilityCode = $this->normalizeCode((string) ($payload['facility_code'] ?? ''));
            $tenant = $this->tenantRepository->findByCode($tenantCode);

            if ($tenant === null) {
                $tenant = $this->tenantRepository->create([
                    'code' => $tenantCode,
                    'name' => trim((string) ($payload['tenant_name'] ?? '')),
                    'country_code' => $this->normalizeCountryCode((string) ($payload['tenant_country_code'] ?? '')),
                    'allowed_country_codes' => $this->normalizeCountryCodes($payload['tenant_allowed_country_codes'] ?? null) ?: null,
                    'status' => 'active',
                ]);
            } else {
                $tenantUpdatePayload = [];
                if (array_key_exists('tenant_allowed_country_codes', $payload)) {
                    $tenantUpdatePayload['allowed_country_codes'] = $this->normalizeCountryCodes($payload['tenant_allowed_country_codes']) ?: null;
                }

                if ($tenantUpdatePayload !== []) {
                    $tenant = $this->tenantRepository->updateById((string) $tenant['id'], $tenantUpdatePayload) ?? $tenant;
                }
            }

            $tenantId = trim((string) ($tenant['id'] ?? ''));
            if ($tenantId === '') {
                throw new InvalidArgumentException('Tenant could not be resolved for facility creation.');
            }

            $facilityAdminUserId = isset($payload['facility_admin_user_id'])
                ? (int) $payload['facility_admin_user_id']
                : null;
            $facilityAdminPayload = isset($payload['facility_admin']) && is_array($payload['facility_admin'])
                ? $payload['facility_admin']
                : null;
            $facilityAdminUser = null;
            $createdFacilityAdminUserId = null;

            if ($facilityAdminUserId !== null && $facilityAdminPayload !== null) {
                throw new DomainException('Choose an existing facility admin or create a new one, not both.');
            }

            if ($facilityAdminUserId === null && $facilityAdminPayload === null) {
                throw new DomainException('Select or create a facility admin before creating the facility.');
            }

            if ($facilityAdminUserId !== null && $facilityAdminUserId > 0) {
                $facilityAdminUser = $this->facilityAdminEligibilityPolicy->findEligibleUser($facilityAdminUserId);
                if ($facilityAdminUser === null) {
                    throw new DomainException('Selected facility admin must be an active user with the Facility Administrator role.');
                }

                $existingTenantId = is_string($facilityAdminUser->tenant_id) && trim($facilityAdminUser->tenant_id) !== ''
                    ? trim($facilityAdminUser->tenant_id)
                    : null;
                if ($existingTenantId !== null && $existingTenantId !== $tenantId) {
                    throw new DomainException('Selected facility admin already belongs to another organization.');
                }
            }

            if ($facilityAdminPayload !== null) {
                $facilityAdminUser = $this->facilityAdminProvisioningService->createFacilityAdmin(
                    payload: $facilityAdminPayload,
                    tenantId: $tenantId,
                    actorId: $actorId,
                );
                $facilityAdminUserId = (int) $facilityAdminUser->id;
                $createdFacilityAdminUserId = $facilityAdminUserId;
            }

            if ($this->facilityConfigurationRepository->existsCodeInTenant($tenantId, $facilityCode)) {
                throw new DuplicateFacilityCodeException('Facility code already exists in this organization.');
            }

            $facility = $this->facilityConfigurationRepository->create([
                'tenant_id' => $tenantId,
                'code' => $facilityCode,
                'name' => trim((string) ($payload['facility_name'] ?? '')),
                'facility_type' => $this->nullableTrimmedValue($payload['facility_type'] ?? null),
                'facility_tier' => $this->nullableTrimmedValue($payload['facility_tier'] ?? null),
                'timezone' => $this->nullableTrimmedValue($payload['timezone'] ?? null),
                'status' => 'active',
                'administrative_owner_user_id' => $facilityAdminUserId,
            ]);

            $facilityId = (string) ($facility['id'] ?? '');

            if ($facilityAdminUser !== null && $facilityAdminUserId !== null) {
                if (! is_string($facilityAdminUser->tenant_id) || trim($facilityAdminUser->tenant_id) === '') {
                    $facilityAdminUser->tenant_id = $tenantId;
                    $facilityAdminUser->save();
                }

                DB::table('facility_user')->updateOrInsert(
                    [
                        'facility_id' => $facilityId,
                        'user_id' => $facilityAdminUserId,
                    ],
                    [
                        'role' => 'facility_admin',
                        'is_primary' => true,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }

            $this->auditLogRepository->write(
                facilityId: $facilityId,
                action: 'platform.facilities.created',
                actorId: $actorId,
                changes: [
                    'tenant' => [
                        'id' => $tenantId,
                        'code' => $tenant['code'] ?? $tenantCode,
                        'name' => $tenant['name'] ?? null,
                    ],
                    'facility' => [
                        'code' => $facility['code'] ?? $facilityCode,
                        'name' => $facility['name'] ?? null,
                        'facility_type' => $facility['facility_type'] ?? null,
                        'timezone' => $facility['timezone'] ?? null,
                    ],
                    'facility_admin_user_id' => $facilityAdminUserId,
                ],
            );

            return [
                'facility' => $this->facilityConfigurationRepository->findById($facilityId) ?? $facility,
                'facility_admin_user_id' => $facilityAdminUserId,
                'created_facility_admin_user_id' => $createdFacilityAdminUserId,
                'created_facility_admin_email' => $facilityAdminUser?->email,
                'facility_id' => $facilityId,
                'tenant_id' => $tenantId,
            ];
        });

        $invite = null;
        $inviteError = null;
        $createdFacilityAdminUserId = $result['created_facility_admin_user_id'] ?? null;

        if (is_int($createdFacilityAdminUserId) && $createdFacilityAdminUserId > 0) {
            try {
                $user = User::query()->find($createdFacilityAdminUserId);
                if ($user !== null) {
                    $invite = $this->facilityAdminProvisioningService->dispatchInviteLink(
                        user: $user,
                        tenantId: (string) ($result['tenant_id'] ?? ''),
                        facilityId: (string) ($result['facility_id'] ?? ''),
                        actorId: $actorId,
                    );
                }
            } catch (Throwable $exception) {
                $inviteError = $exception->getMessage();
            }
        }

        return [
            'facility' => $result['facility'],
            'facility_admin_user_id' => $result['facility_admin_user_id'] ?? null,
            'created_facility_admin_user_id' => $createdFacilityAdminUserId,
            'facility_admin_invite' => $invite,
            'facility_admin_invite_error' => $inviteError,
        ];
    }

    private function normalizeCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function normalizeCountryCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeCountryCodes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(function (mixed $countryCode): ?string {
            if (! is_string($countryCode)) {
                return null;
            }

            $normalized = strtoupper(trim($countryCode));

            return $normalized !== '' ? $normalized : null;
        }, $value))));
    }
}

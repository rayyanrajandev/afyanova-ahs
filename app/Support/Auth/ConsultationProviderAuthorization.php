<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use Illuminate\Contracts\Auth\Authenticatable;

final class ConsultationProviderAuthorization
{
    /**
     * @var array<int, string>
     */
    private const PROVIDER_KEYWORDS = [
        'doctor',
        'medical officer',
        'assistant medical officer',
        'clinical officer',
        'consultant',
        'surgeon',
        'physician',
        'anaesthetist',
        'anesthetist',
        'dentist',
        'dental',
        'medical council',
        'clinical officer',
    ];

    /**
     * @var array<int, string>
     */
    private const EXCLUDED_KEYWORDS = [
        'nurse',
        'midwife',
        'nursing council',
        'midwifery council',
        'laboratory',
        'lab',
        'radiology',
        'radiographer',
        'sonographer',
        'imaging',
        'pharmacist',
        'pharmacy',
        'technician',
        'technologist',
        'medical records',
        'records officer',
        'front desk',
        'cashier',
        'billing',
        'reception',
        'clerk',
    ];

    /**
     * @var array<int, string>
     */
    private const PROVIDER_ROLE_CODES = RoleCodes::CLINICAL_PROVIDER_ROLES;

    /**
     * @var array<int, string>
     */
    private const EXCLUDED_ROLE_CODES = RoleCodes::EXCLUDED_PROVIDER_ROLES;

    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
    ) {}

    public function allows(mixed $user): bool
    {
        if (! $user instanceof Authenticatable || ! method_exists($user, 'hasPermissionTo')) {
            return false;
        }

        if (
            ! (bool) $user->hasPermissionTo('appointments.read')
            || ! (bool) $user->hasPermissionTo('medical.records.read')
            || ! (bool) $user->hasPermissionTo('medical.records.create')
        ) {
            return false;
        }

        $profile = $this->resolveStaffProfile($user);
        if ($profile !== null) {
            return $this->profileAllows($profile);
        }

        return $this->roleFallbackAllows($user);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveStaffProfile(mixed $user): ?array
    {
        $userId = trim((string) ($user->id ?? ''));
        if ($userId === '') {
            return null;
        }

        return $this->staffProfileRepository->findByUserId($userId);
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function profileAllows(array $profile): bool
    {
        $haystack = $this->normalize(implode(' ', array_filter([
            $profile['job_title'] ?? $profile['jobTitle'] ?? null,
            $profile['license_type'] ?? $profile['licenseType'] ?? null,
        ])));

        if ($haystack === '') {
            return false;
        }

        if ($this->containsAny($haystack, self::EXCLUDED_KEYWORDS)) {
            return false;
        }

        return $this->containsAny($haystack, self::PROVIDER_KEYWORDS);
    }

    private function roleFallbackAllows(mixed $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $roleCodes = $user->relationLoaded('roles')
            ? $user->roles->pluck('code')->all()
            : $user->roles()->pluck('code')->all();

        $normalizedCodes = array_values(array_filter(array_map(
            fn (mixed $code): string => strtoupper(trim((string) $code)),
            $roleCodes,
        )));

        foreach (self::EXCLUDED_ROLE_CODES as $roleCode) {
            if (in_array($roleCode, $normalizedCodes, true)) {
                return false;
            }
        }

        foreach (self::PROVIDER_ROLE_CODES as $roleCode) {
            if (in_array($roleCode, $normalizedCodes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $keywords
     */
    private function containsAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($haystack !== '' && str_contains($haystack, $this->normalize($keyword))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(mixed $value): string
    {
        return strtolower(trim((string) ($value ?? '')));
    }
}

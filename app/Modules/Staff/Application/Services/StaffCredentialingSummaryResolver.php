<?php

namespace App\Modules\Staff\Application\Services;

use App\Modules\Staff\Application\Support\StaffCredentialingRequirementEvaluator;
use App\Modules\Staff\Domain\ValueObjects\StaffCredentialingState;
use App\Modules\Staff\Domain\ValueObjects\StaffGoodStandingStatus;
use App\Modules\Staff\Domain\ValueObjects\StaffProfessionalLicenseStatus;
use App\Modules\Staff\Domain\ValueObjects\StaffProfessionalRegistrationStatus;
use App\Modules\Staff\Domain\ValueObjects\StaffProfessionalRegistrationVerificationStatus;

class StaffCredentialingSummaryResolver
{
    private const WATCH_WINDOW_DAYS = 30;

    public function __construct(
        private readonly StaffCredentialingRequirementEvaluator $credentialingRequirementEvaluator,
    ) {}

    /**
     * @param  array<string, mixed>  $staffProfile
     * @param  array<string, mixed>|null  $regulatoryProfile
     * @param  array<int, array<string, mixed>>  $registrations
     * @return array<string, mixed>
     */
    public function resolve(array $staffProfile, ?array $regulatoryProfile, array $registrations): array
    {
        if (! $this->credentialingRequirementEvaluator->requiresCredentialing($staffProfile, $regulatoryProfile, $registrations)) {
            return [
                'id' => $staffProfile['id'] ?? null,
                'credentialing_state' => StaffCredentialingState::NOT_REQUIRED->value,
                'blocking_reasons' => ['Credentialing is not required for this non-clinical staff role.'],
                'next_expiry_at' => null,
                'regulatory_profile' => null,
                'active_registration' => null,
                'registration_summary' => [
                    'total' => count($registrations),
                    'verified' => 0,
                    'pending_verification' => 0,
                    'expired' => 0,
                ],
            ];
        }

        $verifiedActive = $this->sortRegistrations(
            array_values(array_filter(
                $registrations,
                fn (array $registration): bool => $this->isActiveLooking($registration)
                    && ($registration['verification_status'] ?? null) === StaffProfessionalRegistrationVerificationStatus::VERIFIED->value,
            )),
        );
        $pendingActive = $this->sortRegistrations(
            array_values(array_filter(
                $registrations,
                fn (array $registration): bool => $this->isActiveLooking($registration)
                    && ($registration['verification_status'] ?? null) === StaffProfessionalRegistrationVerificationStatus::PENDING->value,
            )),
        );

        $activeRegistration = $verifiedActive[0] ?? $pendingActive[0] ?? null;
        $nextExpiryAt = $this->nextExpiryAt($verifiedActive !== [] ? $verifiedActive : ($pendingActive !== [] ? $pendingActive : $registrations));

        $reasons = [];
        $state = StaffCredentialingState::READY->value;

        if ($regulatoryProfile === null) {
            $reasons[] = 'No regulatory profile is recorded.';
            $state = StaffCredentialingState::BLOCKED->value;
        }

        $goodStandingStatus = $regulatoryProfile['good_standing_status'] ?? null;
        if ($goodStandingStatus === StaffGoodStandingStatus::RESTRICTED->value) {
            $reasons[] = 'Good standing status is restricted.';
            $state = StaffCredentialingState::BLOCKED->value;
        } elseif ($goodStandingStatus === StaffGoodStandingStatus::WITHDRAWN->value) {
            $reasons[] = 'Good standing status is withdrawn.';
            $state = StaffCredentialingState::BLOCKED->value;
        } elseif ($goodStandingStatus === StaffGoodStandingStatus::PENDING->value && $state !== StaffCredentialingState::BLOCKED->value) {
            $reasons[] = 'Good standing verification is pending.';
            $state = StaffCredentialingState::PENDING_VERIFICATION->value;
        }

        if ($state !== StaffCredentialingState::BLOCKED->value) {
            if ($verifiedActive !== []) {
                if ($state !== StaffCredentialingState::PENDING_VERIFICATION->value
                    && $this->isWithinWatchWindow($nextExpiryAt)) {
                    $state = StaffCredentialingState::WATCH->value;
                }
            } elseif ($pendingActive !== []) {
                $reasons[] = 'An active-looking registration is pending verification.';
                $state = StaffCredentialingState::PENDING_VERIFICATION->value;
            } else {
                $reasons[] = 'No active verified registration or license is available.';
                $state = StaffCredentialingState::BLOCKED->value;
            }
        }

        return [
            'id' => $staffProfile['id'] ?? null,
            'credentialing_state' => $state,
            'blocking_reasons' => array_values(array_unique($reasons)),
            'next_expiry_at' => $nextExpiryAt,
            'regulatory_profile' => $regulatoryProfile,
            'active_registration' => $activeRegistration,
            'registration_summary' => [
                'total' => count($registrations),
                'verified' => count(array_filter(
                    $registrations,
                    fn (array $registration): bool => ($registration['verification_status'] ?? null)
                        === StaffProfessionalRegistrationVerificationStatus::VERIFIED->value,
                )),
                'pending_verification' => count(array_filter(
                    $registrations,
                    fn (array $registration): bool => ($registration['verification_status'] ?? null)
                        === StaffProfessionalRegistrationVerificationStatus::PENDING->value,
                )),
                'expired' => count(array_filter(
                    $registrations,
                    fn (array $registration): bool => $this->isExpired($registration),
                )),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $registration
     */
    private function isActiveLooking(array $registration): bool
    {
        $registrationStatus = strtolower((string) ($registration['registration_status'] ?? ''));
        $licenseStatus = strtolower((string) ($registration['license_status'] ?? ''));
        $expiresAt = $this->normalizeDateString($registration['expires_at'] ?? null);

        if ($registrationStatus !== StaffProfessionalRegistrationStatus::ACTIVE->value) {
            return false;
        }

        if (! in_array($licenseStatus, [
            StaffProfessionalLicenseStatus::ACTIVE->value,
            StaffProfessionalLicenseStatus::NOT_REQUIRED->value,
        ], true)) {
            return false;
        }

        return $expiresAt === null || $expiresAt >= now()->startOfDay()->toDateString();
    }

    /**
     * @param  array<string, mixed>  $registration
     */
    private function isExpired(array $registration): bool
    {
        $registrationStatus = strtolower((string) ($registration['registration_status'] ?? ''));
        $licenseStatus = strtolower((string) ($registration['license_status'] ?? ''));
        $expiresAt = $this->normalizeDateString($registration['expires_at'] ?? null);
        $today = now()->startOfDay()->toDateString();

        return $licenseStatus === StaffProfessionalLicenseStatus::EXPIRED->value
            || $registrationStatus === StaffProfessionalRegistrationStatus::EXPIRED->value
            || ($expiresAt !== null && $expiresAt < $today);
    }

    /**
     * @param  array<int, array<string, mixed>>  $registrations
     * @return array<int, array<string, mixed>>
     */
    private function sortRegistrations(array $registrations): array
    {
        usort($registrations, function (array $left, array $right): int {
            $leftExpiry = $this->normalizeDateString($left['expires_at'] ?? null) ?? '9999-12-31';
            $rightExpiry = $this->normalizeDateString($right['expires_at'] ?? null) ?? '9999-12-31';
            $result = strcmp($leftExpiry, $rightExpiry);

            if ($result === 0) {
                $leftUpdatedAt = (string) ($left['updated_at'] ?? '');
                $rightUpdatedAt = (string) ($right['updated_at'] ?? '');
                $result = strcmp($rightUpdatedAt, $leftUpdatedAt);
            }

            return $result;
        });

        return $registrations;
    }

    /**
     * @param  array<int, array<string, mixed>>  $registrations
     */
    private function nextExpiryAt(array $registrations): ?string
    {
        $futureDates = [];
        $today = now()->startOfDay()->toDateString();

        foreach ($registrations as $registration) {
            $expiresAt = $this->normalizeDateString($registration['expires_at'] ?? null);
            if ($expiresAt === null || $expiresAt < $today) {
                continue;
            }

            $futureDates[] = $expiresAt;
        }

        if ($futureDates === []) {
            return null;
        }

        sort($futureDates);

        return $futureDates[0];
    }

    private function isWithinWatchWindow(?string $date): bool
    {
        if ($date === null) {
            return false;
        }

        $watchCutoff = now()->startOfDay()->addDays(self::WATCH_WINDOW_DAYS)->toDateString();

        return $date <= $watchCutoff;
    }

    private function normalizeDateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = substr((string) $value, 0, 10);

        return $normalized === '' ? null : $normalized;
    }
}

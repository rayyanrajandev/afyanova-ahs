<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\CountryProfileRepositoryInterface;

class ConfigCountryProfileRepository implements CountryProfileRepositoryInterface
{
    public function getActiveCode(): string
    {
        return strtoupper((string) config('country_profiles.active', 'TZ'));
    }

    public function findByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        $profile = config('country_profiles.profiles.'.$code);

        return is_array($profile) ? $this->withPatientLocations($profile, $code) : null;
    }

    public function all(): array
    {
        $profiles = config('country_profiles.profiles', []);

        if (! is_array($profiles)) {
            return [];
        }

        $enrichedProfiles = [];
        foreach ($profiles as $code => $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $enrichedProfiles[$code] = $this->withPatientLocations($profile, (string) $code);
        }

        return $enrichedProfiles;
    }

    private function withPatientLocations(array $profile, string $fallbackCode): array
    {
        $profileCode = strtoupper(trim((string) ($profile['code'] ?? $fallbackCode)));
        $locationPresets = config('patient_location_presets.'.$profileCode, []);

        $profile['patientLocations'] = array_values(array_filter(
            is_array($locationPresets) ? $locationPresets : [],
            static fn (mixed $preset): bool => is_array($preset)
        ));

        return $profile;
    }
}

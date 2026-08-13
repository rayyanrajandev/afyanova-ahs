<?php

namespace App\Modules\Staff\Application\Support;

final class StaffCredentialingRequirementEvaluator
{
    /**
     * @var array<int, string>
     */
    private const CLINICAL_ROLE_KEYWORDS = [
        'doctor',
        'surgeon',
        'medical officer',
        'assistant medical officer',
        'clinical officer',
        'anaesthetist',
        'anesthetist',
        'nurse',
        'midwife',
        'laboratory',
        'lab',
        'radiographer',
        'radiology',
        'pharmacist',
        'pharmacy',
        'theatre',
        'recovery',
        'triage',
        'emergency',
        'ward',
        'dispensary',
        'maternity',
        'clinic',
        'outpatient',
        'inpatient',
        'sonographer',
        'physiotherapist',
        'dentist',
        'nutrition',
        'imaging',
        'dental',
    ];

    /**
     * @var array<int, string>
     */
    private const SUPPORT_ROLE_KEYWORDS = [
        'medical records',
        'records officer',
        'registration',
        'front desk',
        'cashier',
        'billing',
        'finance',
        'account',
        'admin',
        'administrator',
        'secretary',
        'reception',
        'procurement',
        'supply',
        'storekeeper',
        'human resources',
        'hr',
        'ict',
        'systems',
        'maintenance',
        'housekeeping',
        'credentialing office',
    ];

    /**
     * @param  array<string, mixed>  $staffProfile
     * @param  array<string, mixed>|null  $regulatoryProfile
     * @param  array<int, array<string, mixed>>  $registrations
     */
    public function requiresCredentialing(
        array $staffProfile,
        ?array $regulatoryProfile = null,
        array $registrations = [],
    ): bool {
        if ($regulatoryProfile !== null || $registrations !== []) {
            return true;
        }

        $jobTitle = $this->normalize($staffProfile['job_title'] ?? $staffProfile['jobTitle'] ?? null);
        $department = $this->normalize($staffProfile['department'] ?? null);
        $licenseType = $this->normalize($staffProfile['license_type'] ?? $staffProfile['licenseType'] ?? null);
        $licenseNumber = $this->normalize($staffProfile['professional_license_number'] ?? $staffProfile['professionalLicenseNumber'] ?? null);

        $roleHaystack = trim(implode(' ', array_filter([$jobTitle, $department])));

        if ($roleHaystack === '' && $licenseNumber === '' && $licenseType === '') {
            return false;
        }

        foreach (self::SUPPORT_ROLE_KEYWORDS as $keyword) {
            if ($this->contains($roleHaystack, $keyword)) {
                return false;
            }
        }

        foreach (self::CLINICAL_ROLE_KEYWORDS as $keyword) {
            if ($this->contains($roleHaystack, $keyword)) {
                return true;
            }
        }

        return $licenseNumber !== '' || $licenseType !== '';
    }

    private function contains(string $haystack, string $keyword): bool
    {
        return $haystack !== '' && str_contains($haystack, strtolower($keyword));
    }

    private function normalize(mixed $value): string
    {
        return strtolower(trim((string) ($value ?? '')));
    }
}

<?php

namespace App\Modules\Appointment\Application\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Resolves the active consultation review policy for a given facility.
 *
 * Priority order:
 *   1. SystemSetting record scoped to the facility (if multi-facility overrides are used)
 *   2. Global SystemSetting record (facility_id IS NULL)
 *   3. Values from config/consultation_policy.php
 */
class ConsultationReviewPolicyResolver
{
    public function resolve(?string $facilityId): array
    {
        $settingsMap = $this->loadSystemSettings($facilityId);

        return [
            'follow_up_days'          => $this->resolveInt('follow_up_days', $settingsMap),
            'review_fee_percentage'   => $this->resolveFloat('review_fee_percentage', $settingsMap),
            'review_fee_is_free'      => $this->resolveBool('review_fee_is_free', $settingsMap),
            'same_complaint_required' => $this->resolveBool('same_complaint_required', $settingsMap),
        ];
    }

    /**
     * Load relevant SystemSetting rows keyed by their setting key.
     * Facility-specific rows win over global rows.
     *
     * @return array<string, string>
     */
    private function loadSystemSettings(?string $facilityId): array
    {
        if (! $this->systemSettingsTableExists()) {
            return [];
        }

        $keys = array_values((array) config('consultation_policy.system_settings_keys', []));
        if ($keys === []) {
            return [];
        }

        $hasFacilityColumn = $this->systemSettingsHasFacilityColumn();

        $query = SystemSetting::query()
            ->whereIn('key', $keys);

        if ($hasFacilityColumn) {
            $query->orderByRaw('CASE WHEN facility_id IS NULL THEN 1 ELSE 0 END DESC');

            if ($facilityId !== null) {
                $query->where(function ($q) use ($facilityId): void {
                    $q->where('facility_id', $facilityId)
                      ->orWhereNull('facility_id');
                });
            } else {
                $query->whereNull('facility_id');
            }
        }

        $map = [];
        $columns = $hasFacilityColumn ? ['key', 'value', 'facility_id'] : ['key', 'value'];

        foreach ($query->get($columns) as $setting) {
            $settingKey = (string) $setting->key;
            // Facility-specific wins: only overwrite if not already set by a facility row
            $alreadyHasFacilityRow = isset($map[$settingKey]) && $map[$settingKey]['is_facility_specific'];
            if (! $alreadyHasFacilityRow) {
                $map[$settingKey] = [
                    'value'               => $setting->value,
                    'is_facility_specific' => $hasFacilityColumn && $setting->facility_id !== null,
                ];
            }
        }

        return array_map(static fn (array $entry): string => (string) $entry['value'], $map);
    }

    private function systemSettingsTableExists(): bool
    {
        try {
            return Schema::hasTable('system_settings');
        } catch (Throwable) {
            return false;
        }
    }

    private function systemSettingsHasFacilityColumn(): bool
    {
        try {
            return Schema::hasColumn('system_settings', 'facility_id');
        } catch (Throwable) {
            return false;
        }
    }

    private function configKey(string $shortKey): string
    {
        $keys = (array) config('consultation_policy.system_settings_keys', []);

        return $keys[$shortKey] ?? '';
    }

    private function resolveInt(string $shortKey, array $settingsMap): int
    {
        $settingKey = $this->configKey($shortKey);
        if ($settingKey !== '' && isset($settingsMap[$settingKey])) {
            return max(0, (int) $settingsMap[$settingKey]);
        }

        return (int) config('consultation_policy.'.$shortKey, 0);
    }

    private function resolveFloat(string $shortKey, array $settingsMap): float
    {
        $settingKey = $this->configKey($shortKey);
        if ($settingKey !== '' && isset($settingsMap[$settingKey])) {
            return $this->normalizeFloat($shortKey, (float) $settingsMap[$settingKey]);
        }

        return $this->normalizeFloat($shortKey, (float) config('consultation_policy.'.$shortKey, 0.0));
    }

    private function resolveBool(string $shortKey, array $settingsMap): bool
    {
        $settingKey = $this->configKey($shortKey);
        if ($settingKey !== '' && isset($settingsMap[$settingKey])) {
            $raw = strtolower(trim($settingsMap[$settingKey]));

            return in_array($raw, ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) config('consultation_policy.'.$shortKey, false);
    }

    private function normalizeFloat(string $shortKey, float $value): float
    {
        if ($shortKey === 'review_fee_percentage') {
            return min(max($value, 0.0), 100.0);
        }

        return $value;
    }
}

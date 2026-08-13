<?php

namespace App\Support\Settings;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemSettingsManager
{
    private const CACHE_KEY = 'system_settings.map.v1';

    private ?bool $tableAvailable = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_array($cached)) {
            return $cached;
        }

        $settings = $this->loadFromDatabase();

        if ($this->settingsTableAvailable()) {
            try {
                Cache::forever(self::CACHE_KEY, $settings);
            } catch (Throwable) {
                //
            }
        }

        return $settings;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->has($key) ? $this->all()[$key] : $default;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function putMany(array $settings): void
    {
        if (! $this->settingsTableAvailable()) {
            throw new \RuntimeException('The system_settings table is not available.');
        }

        DB::transaction(function () use ($settings): void {
            foreach ($settings as $key => $entry) {
                $value = $entry;
                $type = $this->detectType($value);
                $group = $this->groupFromKey($key);

                if (is_array($entry) && array_key_exists('value', $entry)) {
                    $value = $entry['value'];
                    $type = is_string($entry['type'] ?? null)
                        ? trim((string) $entry['type']) ?: $type
                        : $type;
                    $group = is_string($entry['group'] ?? null)
                        ? trim((string) $entry['group']) ?: $group
                        : $group;
                }

                SystemSetting::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'group' => $group,
                        'value' => $this->encodeValue($value, $type),
                        'type' => $type,
                    ],
                );
            }
        });

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            //
        }
    }

    /**
     * @param  array<int, string>  $keys
     */
    public function forgetMany(array $keys): void
    {
        if (! $this->settingsTableAvailable() || $keys === []) {
            return;
        }

        SystemSetting::query()->whereIn('key', $keys)->delete();
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            //
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFromDatabase(): array
    {
        if (! $this->settingsTableAvailable()) {
            return [];
        }

        try {
            return SystemSetting::query()
                ->orderBy('group')
                ->orderBy('key')
                ->get()
                ->mapWithKeys(function (SystemSetting $setting): array {
                    return [
                        $setting->key => $this->decodeValue($setting->value, $setting->type),
                    ];
                })
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function settingsTableAvailable(): bool
    {
        if ($this->tableAvailable !== null) {
            return $this->tableAvailable;
        }

        try {
            return $this->tableAvailable = Schema::hasTable('system_settings');
        } catch (Throwable) {
            return $this->tableAvailable = false;
        }
    }

    private function groupFromKey(string $key): string
    {
        $group = strtok($key, '.');

        return is_string($group) && $group !== '' ? $group : 'general';
    }

    private function detectType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default => 'string',
        };
    }

    private function encodeValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'integer' => (string) (int) $value,
            'float' => (string) (float) $value,
            'json' => json_encode($value, JSON_THROW_ON_ERROR),
            default => trim((string) $value),
        };
    }

    private function decodeValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value === '1',
            'integer' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }
}

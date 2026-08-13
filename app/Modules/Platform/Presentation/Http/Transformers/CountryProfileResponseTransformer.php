<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

class CountryProfileResponseTransformer
{
    public static function transform(array $payload): array
    {
        return [
            'activeCode' => $payload['activeCode'] ?? null,
            'requestedCode' => $payload['requestedCode'] ?? null,
            'profile' => self::transformProfile($payload['profile'] ?? null),
            'availableProfiles' => array_values(array_filter(array_map(
                static fn (mixed $profile): ?array => self::transformProfile($profile),
                is_array($payload['availableProfiles'] ?? null) ? $payload['availableProfiles'] : []
            ))),
            'catalogProfiles' => array_values(array_filter(array_map(
                static fn (mixed $profile): ?array => self::transformProfile($profile),
                is_array($payload['catalogProfiles'] ?? null) ? $payload['catalogProfiles'] : []
            ))),
        ];
    }

    private static function transformProfile(mixed $profile): ?array
    {
        if (! is_array($profile)) {
            return null;
        }

        return [
            'code' => $profile['code'] ?? null,
            'name' => $profile['name'] ?? null,
            'timezone' => $profile['timezone'] ?? null,
            'currencyCode' => $profile['currencyCode'] ?? null,
            'languages' => array_values(is_array($profile['languages'] ?? null) ? $profile['languages'] : []),
            'locales' => array_values(is_array($profile['locales'] ?? null) ? $profile['locales'] : []),
            'patientAddressing' => [
                'regionLabel' => $profile['patientAddressing']['regionLabel'] ?? null,
                'districtLabel' => $profile['patientAddressing']['districtLabel'] ?? null,
                'regionPlaceholder' => $profile['patientAddressing']['regionPlaceholder'] ?? null,
                'districtPlaceholder' => $profile['patientAddressing']['districtPlaceholder'] ?? null,
                'addressLabel' => $profile['patientAddressing']['addressLabel'] ?? null,
                'addressPlaceholder' => $profile['patientAddressing']['addressPlaceholder'] ?? null,
            ],
            'patientLocations' => self::transformPatientLocations($profile['patientLocations'] ?? null),
            'regulatory' => is_array($profile['regulatory'] ?? null) ? $profile['regulatory'] : [],
            'interoperability' => is_array($profile['interoperability'] ?? null) ? $profile['interoperability'] : [],
            'billing' => is_array($profile['billing'] ?? null) ? $profile['billing'] : [],
        ];
    }

    private static function transformPatientLocations(mixed $presets): array
    {
        if (! is_array($presets)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (mixed $preset): ?array {
                if (! is_array($preset)) {
                    return null;
                }

                return [
                    'value' => $preset['value'] ?? null,
                    'label' => $preset['label'] ?? null,
                    'description' => $preset['description'] ?? null,
                    'keywords' => array_values(array_filter(
                        is_array($preset['keywords'] ?? null) ? $preset['keywords'] : [],
                        static fn (mixed $keyword): bool => is_string($keyword) && trim($keyword) !== ''
                    )),
                    'districts' => array_values(array_filter(
                        is_array($preset['districts'] ?? null) ? $preset['districts'] : [],
                        static fn (mixed $district): bool => is_string($district) && trim($district) !== ''
                    )),
                ];
            },
            $presets
        )));
    }
}

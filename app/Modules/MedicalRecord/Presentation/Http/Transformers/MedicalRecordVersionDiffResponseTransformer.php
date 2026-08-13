<?php

namespace App\Modules\MedicalRecord\Presentation\Http\Transformers;

class MedicalRecordVersionDiffResponseTransformer
{
    public static function transform(array $payload): array
    {
        return [
            'targetVersion' => self::transformVersionMeta($payload['targetVersion'] ?? null),
            'baseVersion' => self::transformVersionMeta($payload['baseVersion'] ?? null),
            'diff' => self::transformDiffRows($payload['diff'] ?? []),
            'summary' => [
                'changedFieldCount' => (int) ($payload['summary']['changedFieldCount'] ?? 0),
            ],
        ];
    }

    /**
     * @param  mixed  $version
     */
    private static function transformVersionMeta($version): ?array
    {
        if (! is_array($version)) {
            return null;
        }

        return [
            'id' => $version['id'] ?? null,
            'medicalRecordId' => $version['medical_record_id'] ?? null,
            'versionNumber' => $version['version_number'] ?? null,
            'changedFields' => is_array($version['changed_fields'] ?? null) ? $version['changed_fields'] : [],
            'createdByUserId' => $version['created_by_user_id'] ?? null,
            'createdAt' => $version['created_at'] ?? null,
        ];
    }

    /**
     * @param  mixed  $diff
     * @return array<int, array<string, mixed>>
     */
    private static function transformDiffRows($diff): array
    {
        if (! is_array($diff)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $row): array => [
                'field' => is_array($row) ? ($row['field'] ?? null) : null,
                'before' => is_array($row) ? ($row['before'] ?? null) : null,
                'after' => is_array($row) ? ($row['after'] ?? null) : null,
            ],
            $diff,
        ));
    }
}

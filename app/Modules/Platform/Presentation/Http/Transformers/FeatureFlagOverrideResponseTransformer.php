<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

class FeatureFlagOverrideResponseTransformer
{
    public static function transform(array $row): array
    {
        return [
            'id' => $row['id'] ?? null,
            'flagName' => $row['flag_name'] ?? ($row['flagName'] ?? null),
            'scopeType' => $row['scope_type'] ?? ($row['scopeType'] ?? null),
            'scopeKey' => $row['scope_key'] ?? ($row['scopeKey'] ?? null),
            'enabled' => (bool) ($row['enabled'] ?? false),
            'reason' => $row['reason'] ?? null,
            'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : null,
            'createdAt' => $row['created_at'] ?? ($row['createdAt'] ?? null),
            'updatedAt' => $row['updated_at'] ?? ($row['updatedAt'] ?? null),
        ];
    }
}

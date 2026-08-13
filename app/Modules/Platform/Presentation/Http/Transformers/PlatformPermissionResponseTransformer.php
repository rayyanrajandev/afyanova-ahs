<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

class PlatformPermissionResponseTransformer
{
    public static function transform(array $permission): array
    {
        return [
            'id' => $permission['id'] ?? null,
            'name' => $permission['name'] ?? null,
            'createdAt' => $permission['created_at'] ?? null,
            'updatedAt' => $permission['updated_at'] ?? null,
        ];
    }
}


<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

class PlatformRoleResponseTransformer
{
    public static function transform(array $role): array
    {
        $code = isset($role['code']) ? (string) $role['code'] : null;

        return [
            'id' => $role['id'] ?? null,
            'tenantId' => $role['tenant_id'] ?? null,
            'facilityId' => $role['facility_id'] ?? null,
            'code' => $code,
            'name' => $role['name'] ?? null,
            'status' => $role['status'] ?? null,
            'description' => $role['description'] ?? null,
            'isSystem' => (bool) ($role['is_system'] ?? false),
            'riskTier' => self::resolveRiskTier($code),
            'isElevated' => self::isElevatedRoleCode($code),
            'permissionNames' => $role['permission_names'] ?? [],
            'permissionsCount' => (int) ($role['permissions_count'] ?? 0),
            'usersCount' => (int) ($role['users_count'] ?? 0),
            'createdAt' => $role['created_at'] ?? null,
            'updatedAt' => $role['updated_at'] ?? null,
        ];
    }

    public static function resolveRiskTier(?string $roleCode): string
    {
        $code = strtoupper(trim((string) $roleCode));

        if ($code === '') {
            return 'other';
        }

        if (str_contains($code, 'SUPER.ADMIN') || str_starts_with($code, 'SYSTEM.')) {
            return 'system';
        }

        if (str_starts_with($code, 'PLATFORM.')) {
            return 'platform';
        }

        if (str_starts_with($code, 'ADMIN.')
            || str_starts_with($code, 'CLINICAL.')
            || str_starts_with($code, 'FINANCE.')
            || str_starts_with($code, 'LAB.')
            || str_starts_with($code, 'RADIOLOGY.')
            || str_starts_with($code, 'PHARMACY.')
            || str_starts_with($code, 'THEATRE.')
            || str_starts_with($code, 'INVENTORY.')
            || str_starts_with($code, 'HOSPITAL.')
        ) {
            return 'hospital';
        }

        return 'other';
    }

    public static function isElevatedRoleCode(?string $roleCode): bool
    {
        $code = strtoupper(trim((string) $roleCode));

        if ($code === '') {
            return false;
        }

        return str_starts_with($code, 'PLATFORM.')
            || str_contains($code, 'SUPER.ADMIN')
            || $code === 'ADMIN.FACILITY';
    }
}


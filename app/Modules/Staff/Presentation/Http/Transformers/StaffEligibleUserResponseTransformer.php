<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

class StaffEligibleUserResponseTransformer
{
    /**
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>
     */
    public static function transform(array $user): array
    {
        $name = trim((string) ($user['name'] ?? ''));
        $email = trim((string) ($user['email'] ?? ''));
        $displayName = $name !== ''
            ? $name
            : ($email !== '' ? $email : 'Unnamed user');

        return [
            'id' => isset($user['id']) ? (int) $user['id'] : null,
            'name' => $name !== '' ? $name : null,
            'email' => $email !== '' ? $email : null,
            'displayName' => $displayName,
            'status' => $user['status'] ?? null,
            'emailVerifiedAt' => $user['email_verified_at'] ?? null,
            'roleLabels' => array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                (array) ($user['role_labels'] ?? []),
            ))),
            'facilityLabels' => array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                (array) ($user['facility_labels'] ?? []),
            ))),
            'primaryFacilityLabel' => ($user['primary_facility_label'] ?? null) ?: null,
        ];
    }
}

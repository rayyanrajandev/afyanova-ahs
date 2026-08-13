<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

use App\Models\User;

class StaffPrivilegeGrantResponseTransformer
{
    /** @var array<int, array<string, mixed>|null> */
    private static array $actorCache = [];

    public static function transform(array $grant): array
    {
        $reviewerUserId = self::toNullableInt($grant['reviewer_user_id'] ?? null);
        $approverUserId = self::toNullableInt($grant['approver_user_id'] ?? null);

        return [
            'id' => $grant['id'] ?? null,
            'staffProfileId' => $grant['staff_profile_id'] ?? null,
            'tenantId' => $grant['tenant_id'] ?? null,
            'facilityId' => $grant['facility_id'] ?? null,
            'specialtyId' => $grant['specialty_id'] ?? null,
            'privilegeCatalogId' => $grant['privilege_catalog_id'] ?? null,
            'privilegeCode' => $grant['privilege_code'] ?? null,
            'privilegeName' => $grant['privilege_name'] ?? null,
            'scopeNotes' => $grant['scope_notes'] ?? null,
            'grantedAt' => $grant['granted_at'] ?? null,
            'reviewDueAt' => $grant['review_due_at'] ?? null,
            'requestedAt' => $grant['requested_at'] ?? null,
            'reviewStartedAt' => $grant['review_started_at'] ?? null,
            'approvedAt' => $grant['approved_at'] ?? null,
            'activatedAt' => $grant['activated_at'] ?? null,
            'status' => $grant['status'] ?? null,
            'statusReason' => $grant['status_reason'] ?? null,
            'grantedByUserId' => $grant['granted_by_user_id'] ?? null,
            'reviewerUserId' => $reviewerUserId,
            'reviewNote' => $grant['review_note'] ?? null,
            'reviewerUser' => self::resolveActor($reviewerUserId),
            'approverUserId' => $approverUserId,
            'approvalNote' => $grant['approval_note'] ?? null,
            'approverUser' => self::resolveActor($approverUserId),
            'updatedByUserId' => $grant['updated_by_user_id'] ?? null,
            'createdAt' => $grant['created_at'] ?? null,
            'updatedAt' => $grant['updated_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function resolveActor(?int $actorId): ?array
    {
        if ($actorId === null) {
            return null;
        }

        if (array_key_exists($actorId, self::$actorCache)) {
            return self::$actorCache[$actorId];
        }

        $user = User::query()
            ->select(['id', 'name', 'email'])
            ->find($actorId);

        $payload = $user
            ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'displayName' => trim((string) $user->name) !== '' ? $user->name : sprintf('User #%d', $user->id),
            ]
            : [
                'id' => $actorId,
                'name' => null,
                'email' => null,
                'displayName' => sprintf('User #%d', $actorId),
            ];

        self::$actorCache[$actorId] = $payload;

        return $payload;
    }

    private static function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}

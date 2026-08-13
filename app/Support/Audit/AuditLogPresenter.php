<?php

namespace App\Support\Audit;

use App\Models\User;

class AuditLogPresenter
{
    /** @var array<int, array{name: string|null, email: string|null}> */
    private static array $actorCache = [];

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $rawLog
     * @param  array<string, string>  $actionLabels
     * @return array<string, mixed>
     */
    public static function enrich(array $payload, array $rawLog, array $actionLabels = []): array
    {
        $actorId = self::toNullableInt($payload['actorId'] ?? ($rawLog['actor_id'] ?? null));
        $actionKey = self::toNullableString($payload['action'] ?? ($rawLog['action'] ?? null));
        $actorType = $actorId === null ? 'system' : 'user';

        $actorName = self::toNullableString($rawLog['actor_name'] ?? null);
        $actorEmail = self::toNullableString($rawLog['actor_email'] ?? null);

        if ($actorId !== null && ($actorName === null || $actorEmail === null)) {
            $resolved = self::resolveActor($actorId);
            $actorName ??= $resolved['name'];
            $actorEmail ??= $resolved['email'];
        }

        $payload['actorId'] = $actorId;
        $payload['actorType'] = $actorType;
        $payload['actor'] = [
            'id' => $actorId,
            'name' => $actorName,
            'email' => $actorEmail,
            'displayName' => self::actorDisplayName($actorId, $actorName),
        ];
        $payload['action'] = $actionKey;
        $payload['actionLabel'] = self::actionLabel($actionKey, $actionLabels);

        return $payload;
    }

    /**
     * @return array{name: string|null, email: string|null}
     */
    private static function resolveActor(int $actorId): array
    {
        if (array_key_exists($actorId, self::$actorCache)) {
            return self::$actorCache[$actorId];
        }

        $user = User::query()
            ->select(['id', 'name', 'email'])
            ->find($actorId);

        $resolved = [
            'name' => self::toNullableString($user?->name),
            'email' => self::toNullableString($user?->email),
        ];

        self::$actorCache[$actorId] = $resolved;

        return $resolved;
    }

    /**
     * @param  array<string, string>  $actionLabels
     */
    private static function actionLabel(?string $action, array $actionLabels): string
    {
        if ($action === null) {
            return 'Event';
        }

        if (array_key_exists($action, $actionLabels)) {
            return $actionLabels[$action];
        }

        return self::titleCaseAction($action);
    }

    private static function actorDisplayName(?int $actorId, ?string $actorName): string
    {
        if ($actorId === null) {
            return 'System';
        }

        if ($actorName !== null && $actorName !== '') {
            return $actorName;
        }

        return sprintf('User #%d', $actorId);
    }

    private static function titleCaseAction(string $action): string
    {
        $normalized = str_replace(['.', '_', '-'], ' ', strtolower(trim($action)));
        if ($normalized === '') {
            return 'Event';
        }

        return ucwords(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    private static function toNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
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

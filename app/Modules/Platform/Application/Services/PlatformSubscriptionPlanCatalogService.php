<?php

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Infrastructure\Models\PlatformSubscriptionPlanAuditLogModel;
use App\Modules\Platform\Infrastructure\Models\PlatformSubscriptionPlanModel;
use App\Modules\Platform\Presentation\Http\Transformers\PlatformSubscriptionPlanAuditLogResponseTransformer;
use App\Modules\Platform\Presentation\Http\Transformers\PlatformSubscriptionPlanResponseTransformer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PlatformSubscriptionPlanCatalogService
{
    private const ACTION_UPDATED = 'platform.subscription-plans.updated';

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function list(array $filters): array
    {
        $query = PlatformSubscriptionPlanModel::query()
            ->with('entitlements');

        $search = $this->nullableTrimmedValue($filters['q'] ?? null);
        if ($search !== null) {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function ($query) use ($needle): void {
                $query
                    ->whereRaw('LOWER(code) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$needle]);
            });
        }

        $status = $this->nullableTrimmedValue($filters['status'] ?? null);
        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('status', $status);
        }

        $paginator = $query
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(
                perPage: $this->perPage($filters['perPage'] ?? null),
                page: max(1, (int) ($filters['page'] ?? 1)),
            );

        return $this->paginatedPlans($paginator);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(string $id): ?array
    {
        $plan = PlatformSubscriptionPlanModel::query()
            ->with('entitlements')
            ->find($id);

        return $plan ? PlatformSubscriptionPlanResponseTransformer::transform($plan) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $payload, ?int $actorId = null): ?array
    {
        $exists = PlatformSubscriptionPlanModel::query()->whereKey($id)->exists();
        if (! $exists) {
            return null;
        }

        $plan = DB::transaction(function () use ($id, $payload, $actorId): PlatformSubscriptionPlanModel {
            $plan = PlatformSubscriptionPlanModel::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            $plan->load('entitlements');
            $before = $this->auditSnapshot($plan);

            $plan->fill([
                'name' => trim((string) $payload['name']),
                'description' => $this->nullableTrimmedValue($payload['description'] ?? null),
                'billing_cycle' => (string) $payload['billingCycle'],
                'price_amount' => $this->moneyValue($payload['priceAmount']),
                'currency_code' => strtoupper((string) $payload['currencyCode']),
                'status' => (string) $payload['status'],
            ]);
            $plan->save();

            $this->updateEntitlements($plan, $payload['entitlements'] ?? []);

            $plan->refresh()->load('entitlements');

            $after = $this->auditSnapshot($plan);
            $changes = $this->diffSnapshots($before, $after);

            if ($changes !== []) {
                PlatformSubscriptionPlanAuditLogModel::query()->create([
                    'plan_id' => $plan->id,
                    'actor_id' => $actorId,
                    'action' => self::ACTION_UPDATED,
                    'changes' => $changes,
                    'metadata' => [
                        'plan_code' => $plan->code,
                        'plan_name' => $plan->name,
                        'active_facility_subscriptions' => DB::table('facility_subscriptions')
                            ->where('plan_id', $plan->id)
                            ->whereIn('status', ['trial', 'active', 'grace_period'])
                            ->count(),
                    ],
                    'created_at' => now(),
                ]);
            }

            return $plan;
        });

        return PlatformSubscriptionPlanResponseTransformer::transform($plan);
    }

    /**
     * Create a new entitlement on a plan. Enables 2027 feature creation
     * without a migration: the caller explicitly provides the key, label,
     * group, type, and enabled state (per-plan enablement is explicit, never
     * "all plans by default").
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null  null when the plan does not exist
     */
    public function createEntitlement(string $id, array $payload, ?int $actorId = null): ?array
    {
        $plan = DB::transaction(function () use ($id, $payload, $actorId): ?PlatformSubscriptionPlanModel {
            $plan = PlatformSubscriptionPlanModel::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (! $plan) {
                return null;
            }

            $plan->load('entitlements');

            $before = $this->auditSnapshot($plan);

            $entitlementKey = strtolower(trim((string) $payload['entitlementKey']));
            $exists = $plan->entitlements->contains(
                static fn ($entitlement): bool => strtolower(trim((string) $entitlement->entitlement_key)) === $entitlementKey,
            );

            if ($exists) {
                throw new \InvalidArgumentException('This plan already includes the entitlement '.$entitlementKey.'.');
            }

            $plan->entitlements()->create([
                'entitlement_key' => $entitlementKey,
                'entitlement_label' => trim((string) $payload['entitlementLabel']),
                'entitlement_group' => $this->nullableTrimmedValue($payload['entitlementGroup'] ?? null),
                'entitlement_type' => (string) ($payload['entitlementType'] ?? 'feature'),
                'limit_value' => $this->nullableIntegerValue($payload['limitValue'] ?? null),
                'enabled' => (bool) ($payload['enabled'] ?? true),
                'metadata' => [
                    'created_via' => 'api',
                    'catalog_version' => config('plan_catalog.version', 'dev'),
                ],
            ]);

            $plan->refresh()->load('entitlements');

            $after = $this->auditSnapshot($plan);
            $changes = $this->diffSnapshots($before, $after);

            if ($changes !== []) {
                PlatformSubscriptionPlanAuditLogModel::query()->create([
                    'plan_id' => $plan->id,
                    'actor_id' => $actorId,
                    'action' => self::ACTION_UPDATED,
                    'changes' => $changes,
                    'metadata' => [
                        'plan_code' => $plan->code,
                        'plan_name' => $plan->name,
                        'entitlement_key' => $entitlementKey,
                        'created_via' => 'api',
                    ],
                    'created_at' => now(),
                ]);
            }

            return $plan;
        });

        return $plan ? PlatformSubscriptionPlanResponseTransformer::transform($plan) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function updateEntitlement(string $id, string $entitlementId, array $payload, ?int $actorId = null): ?array
    {
        $plan = DB::transaction(function () use ($id, $entitlementId, $payload, $actorId): ?PlatformSubscriptionPlanModel {
            $plan = PlatformSubscriptionPlanModel::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (! $plan) {
                return null;
            }

            $plan->load('entitlements');
            $entitlement = $plan->entitlements->firstWhere('id', $entitlementId);

            if (! $entitlement) {
                return null;
            }

            $before = $this->auditSnapshot($plan);

            $entitlement->fill([
                'enabled' => (bool) $payload['enabled'],
                'limit_value' => array_key_exists('limitValue', $payload)
                    ? $this->nullableIntegerValue($payload['limitValue'])
                    : $entitlement->limit_value,
            ]);
            $entitlement->save();

            $plan->refresh()->load('entitlements');

            $after = $this->auditSnapshot($plan);
            $changes = $this->diffSnapshots($before, $after);

            if ($changes !== []) {
                PlatformSubscriptionPlanAuditLogModel::query()->create([
                    'plan_id' => $plan->id,
                    'actor_id' => $actorId,
                    'action' => self::ACTION_UPDATED,
                    'changes' => $changes,
                    'metadata' => [
                        'plan_code' => $plan->code,
                        'plan_name' => $plan->name,
                        'entitlement_id' => $entitlementId,
                        'entitlement_key' => $entitlement->entitlement_key,
                        'active_facility_subscriptions' => DB::table('facility_subscriptions')
                            ->where('plan_id', $plan->id)
                            ->whereIn('status', ['trial', 'active', 'grace_period'])
                            ->count(),
                    ],
                    'created_at' => now(),
                ]);
            }

            return $plan;
        });

        return $plan ? PlatformSubscriptionPlanResponseTransformer::transform($plan) : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}|null
     */
    public function auditLogs(string $id, array $filters): ?array
    {
        $exists = PlatformSubscriptionPlanModel::query()->whereKey($id)->exists();
        if (! $exists) {
            return null;
        }

        $paginator = PlatformSubscriptionPlanAuditLogModel::query()
            ->where('plan_id', $id)
            ->orderByDesc('created_at')
            ->paginate(
                perPage: $this->perPage($filters['perPage'] ?? null, 20),
                page: max(1, (int) ($filters['page'] ?? 1)),
            );

        return [
            'data' => $paginator
                ->getCollection()
                ->map(static fn (PlatformSubscriptionPlanAuditLogModel $log): array => PlatformSubscriptionPlanAuditLogResponseTransformer::transform($log))
                ->values()
                ->all(),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function moneyValue(mixed $value): string
    {
        return number_format(max(0, (float) $value), 2, '.', '');
    }

    private function nullableIntegerValue(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entitlements
     */
    private function updateEntitlements(PlatformSubscriptionPlanModel $plan, array $entitlements): void
    {
        if ($entitlements === []) {
            return;
        }

        $updatesById = collect($entitlements)->keyBy(static fn (array $entitlement): string => (string) ($entitlement['id'] ?? ''));

        foreach ($plan->entitlements as $entitlement) {
            $update = $updatesById->get((string) $entitlement->id);
            if (! is_array($update)) {
                continue;
            }

            $entitlement->fill([
                'enabled' => (bool) ($update['enabled'] ?? false),
                'limit_value' => $this->nullableIntegerValue($update['limitValue'] ?? null),
            ]);
            $entitlement->save();
        }
    }

    private function perPage(mixed $value, int $default = 20): int
    {
        return max(1, min((int) ($value ?: $default), 50));
    }

    /**
     * @param  LengthAwarePaginator<int, PlatformSubscriptionPlanModel>  $paginator
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    private function paginatedPlans(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator
                ->getCollection()
                ->map(static fn (PlatformSubscriptionPlanModel $plan): array => PlatformSubscriptionPlanResponseTransformer::transform($plan))
                ->values()
                ->all(),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(PlatformSubscriptionPlanModel $plan): array
    {
        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'billing_cycle' => $plan->billing_cycle,
            'price_amount' => $this->moneyValue($plan->price_amount),
            'currency_code' => $plan->currency_code,
            'status' => $plan->status,
            'entitlements' => $plan->entitlements
                ->mapWithKeys(static fn ($entitlement): array => [
                    (string) $entitlement->entitlement_key => [
                        'enabled' => (bool) $entitlement->enabled,
                        'limit_value' => $entitlement->limit_value,
                    ],
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array<string, mixed>>
     */
    private function diffSnapshots(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $afterValue) {
            $beforeValue = $before[$field] ?? null;
            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[$field] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }

    /**
     * @return array<string, int>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'currentPage' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'lastPage' => $paginator->lastPage(),
        ];
    }
}

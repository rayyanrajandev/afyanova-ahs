<?php

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Domain\Repositories\FacilityConfigurationAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FacilityConfigurationRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\FacilitySubscriptionStatus;
use App\Modules\Platform\Infrastructure\Models\FacilitySubscriptionAuditLogModel;
use App\Modules\Platform\Infrastructure\Models\FacilitySubscriptionModel;
use App\Modules\Platform\Infrastructure\Models\PlatformSubscriptionPlanModel;
use App\Modules\Platform\Presentation\Http\Transformers\FacilitySubscriptionResponseTransformer;
use App\Modules\Platform\Presentation\Http\Transformers\PlatformSubscriptionPlanResponseTransformer;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class FacilitySubscriptionManagementService
{
    public function __construct(
        private readonly FacilityConfigurationRepositoryInterface $facilityConfigurationRepository,
        private readonly FacilityConfigurationAuditLogRepositoryInterface $facilityAuditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activePlans(): array
    {
        return PlatformSubscriptionPlanModel::query()
            ->with('entitlements')
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(static fn (PlatformSubscriptionPlanModel $plan): array => PlatformSubscriptionPlanResponseTransformer::transform($plan))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function subscriptionForFacility(string $facilityId): ?array
    {
        $facility = $this->facilityConfigurationRepository->findById($facilityId);
        if (! $facility) {
            return null;
        }

        $subscription = $this->findSubscriptionModel($facilityId);

        return $subscription
            ? FacilitySubscriptionResponseTransformer::transform($subscription)
            : FacilitySubscriptionResponseTransformer::empty($facilityId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function updateSubscriptionForFacility(string $facilityId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $facility = $this->facilityConfigurationRepository->findById($facilityId);
        if (! $facility) {
            return null;
        }

        $plan = PlatformSubscriptionPlanModel::query()
            ->with('entitlements')
            ->where('id', (string) ($payload['plan_id'] ?? ''))
            ->first();

        if (! $plan || $plan->status !== 'active') {
            throw new DomainException('Select an active subscription plan before saving this facility subscription.');
        }

        $subscription = DB::transaction(function () use ($facilityId, $facility, $payload, $plan, $actorId): FacilitySubscriptionModel {
            $subscription = FacilitySubscriptionModel::query()
                ->where('facility_id', $facilityId)
                ->first();
            $subscription?->load('plan');
            $before = $subscription ? $this->auditSnapshot($subscription) : [];

            $attributes = [
                'tenant_id' => (string) ($facility['tenant_id'] ?? ''),
                'facility_id' => $facilityId,
                'plan_id' => $plan->id,
                'status' => (string) $payload['status'],
                'billing_cycle' => (string) $plan->billing_cycle,
                'price_amount' => $this->moneyValue($plan->price_amount),
                'currency_code' => strtoupper((string) $plan->currency_code),
                'trial_ends_at' => $this->nullableDateTime($payload['trial_ends_at'] ?? null),
                'current_period_starts_at' => $this->nullableDateTime($payload['current_period_starts_at'] ?? null),
                'current_period_ends_at' => $this->nullableDateTime($payload['current_period_ends_at'] ?? null),
                'next_invoice_at' => $this->nullableDateTime($payload['next_invoice_at'] ?? null),
                'grace_period_ends_at' => $this->nullableDateTime($payload['grace_period_ends_at'] ?? null),
                'status_reason' => $this->nullableTrimmedValue($payload['status_reason'] ?? null),
                'metadata' => $this->subscriptionMetadata($plan),
            ];

            $attributes = $this->applyStatusTimestamps($attributes, $subscription);

            if (! $subscription) {
                $subscription = FacilitySubscriptionModel::query()->create($attributes);
            } else {
                $subscription->fill($attributes);
                $subscription->save();
            }

            $subscription->refresh()->load('plan.entitlements');
            $after = $this->auditSnapshot($subscription);
            $changes = $this->diffSnapshots($before, $after);

            if ($changes !== []) {
                $metadata = [
                    'plan_code' => $plan->code,
                    'plan_name' => $plan->name,
                    'entitlement_keys' => $plan->entitlements
                        ->where('enabled', true)
                        ->pluck('entitlement_key')
                        ->values()
                        ->all(),
                ];

                FacilitySubscriptionAuditLogModel::query()->create([
                    'facility_subscription_id' => $subscription->id,
                    'facility_id' => $facilityId,
                    'actor_id' => $actorId,
                    'action' => 'platform.facilities.subscription.updated',
                    'changes' => $changes,
                    'metadata' => $metadata,
                    'created_at' => now(),
                ]);

                $this->facilityAuditLogRepository->write(
                    facilityId: $facilityId,
                    action: 'platform.facilities.subscription.updated',
                    actorId: $actorId,
                    changes: $changes,
                    metadata: $metadata,
                );
            }

            return $subscription;
        });

        return FacilitySubscriptionResponseTransformer::transform($subscription);
    }

    private function findSubscriptionModel(string $facilityId): ?FacilitySubscriptionModel
    {
        return FacilitySubscriptionModel::query()
            ->with('plan.entitlements')
            ->where('facility_id', $facilityId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function applyStatusTimestamps(array $attributes, ?FacilitySubscriptionModel $existing): array
    {
        $status = (string) $attributes['status'];

        $attributes['suspended_at'] = $status === FacilitySubscriptionStatus::SUSPENDED->value
            ? ($existing?->suspended_at ?? now())
            : null;

        $attributes['cancellation_effective_at'] = $status === FacilitySubscriptionStatus::CANCELLED->value
            ? ($existing?->cancellation_effective_at ?? now())
            : null;

        return $attributes;
    }

    private function nullableDateTime(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->toDateTimeString();
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

    /**
     * @return array<string, mixed>
     */
    private function subscriptionMetadata(PlatformSubscriptionPlanModel $plan): array
    {
        return [
            'plan_code' => $plan->code,
            'plan_name' => $plan->name,
            'entitlement_keys' => $plan->entitlements
                ->where('enabled', true)
                ->pluck('entitlement_key')
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(FacilitySubscriptionModel $subscription): array
    {
        return [
            'plan_id' => $subscription->plan_id,
            'plan_code' => $subscription->plan?->code,
            'status' => $subscription->status,
            'billing_cycle' => $subscription->billing_cycle,
            'price_amount' => $subscription->price_amount,
            'currency_code' => $subscription->currency_code,
            'trial_ends_at' => $this->auditDate($subscription->trial_ends_at),
            'current_period_starts_at' => $this->auditDate($subscription->current_period_starts_at),
            'current_period_ends_at' => $this->auditDate($subscription->current_period_ends_at),
            'next_invoice_at' => $this->auditDate($subscription->next_invoice_at),
            'grace_period_ends_at' => $this->auditDate($subscription->grace_period_ends_at),
            'suspended_at' => $this->auditDate($subscription->suspended_at),
            'cancellation_effective_at' => $this->auditDate($subscription->cancellation_effective_at),
            'status_reason' => $subscription->status_reason,
        ];
    }

    private function auditDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
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
}

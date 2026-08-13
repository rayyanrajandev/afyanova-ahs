<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

use App\Modules\Platform\Domain\ValueObjects\FacilitySubscriptionStatus;
use App\Modules\Platform\Infrastructure\Models\FacilitySubscriptionModel;
use DateTimeInterface;

class FacilitySubscriptionResponseTransformer
{
    /**
     * @return array<string, mixed>
     */
    public static function empty(string $facilityId): array
    {
        return [
            'id' => null,
            'tenantId' => null,
            'facilityId' => $facilityId,
            'planId' => null,
            'plan' => null,
            'status' => 'not_configured',
            'billingCycle' => null,
            'priceAmount' => null,
            'currencyCode' => null,
            'trialEndsAt' => null,
            'currentPeriodStartsAt' => null,
            'currentPeriodEndsAt' => null,
            'nextInvoiceAt' => null,
            'gracePeriodEndsAt' => null,
            'suspendedAt' => null,
            'cancellationEffectiveAt' => null,
            'statusReason' => null,
            'metadata' => [],
            'entitlementKeys' => [],
            'accessEnabled' => false,
            'accessState' => 'not_configured',
            'createdAt' => null,
            'updatedAt' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function transform(FacilitySubscriptionModel $subscription): array
    {
        $subscription->loadMissing('plan.entitlements');
        $status = (string) $subscription->status;
        $isExpired = self::isExpiredForStatus($subscription, $status);
        $accessEnabled = FacilitySubscriptionStatus::allowsAccess($status) && ! $isExpired;
        $plan = $subscription->plan;

        return [
            'id' => $subscription->id,
            'tenantId' => $subscription->tenant_id,
            'facilityId' => $subscription->facility_id,
            'planId' => $subscription->plan_id,
            'plan' => $plan ? PlatformSubscriptionPlanResponseTransformer::transform($plan) : null,
            'status' => $status,
            'billingCycle' => $subscription->billing_cycle,
            'priceAmount' => $subscription->price_amount,
            'currencyCode' => $subscription->currency_code,
            'trialEndsAt' => self::iso($subscription->trial_ends_at),
            'currentPeriodStartsAt' => self::iso($subscription->current_period_starts_at),
            'currentPeriodEndsAt' => self::iso($subscription->current_period_ends_at),
            'nextInvoiceAt' => self::iso($subscription->next_invoice_at),
            'gracePeriodEndsAt' => self::iso($subscription->grace_period_ends_at),
            'suspendedAt' => self::iso($subscription->suspended_at),
            'cancellationEffectiveAt' => self::iso($subscription->cancellation_effective_at),
            'statusReason' => $subscription->status_reason,
            'metadata' => $subscription->metadata ?? [],
            'entitlementKeys' => $plan
                ? $plan->entitlements
                    ->where('enabled', true)
                    ->pluck('entitlement_key')
                    ->values()
                    ->all()
                : [],
            'accessEnabled' => $accessEnabled,
            'accessState' => self::accessState($status, $isExpired, $accessEnabled),
            'createdAt' => self::iso($subscription->created_at),
            'updatedAt' => self::iso($subscription->updated_at),
        ];
    }

    private static function iso(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function isExpiredForStatus(FacilitySubscriptionModel $subscription, string $status): bool
    {
        return match ($status) {
            FacilitySubscriptionStatus::TRIAL->value => self::isExpired($subscription->trial_ends_at),
            FacilitySubscriptionStatus::GRACE_PERIOD->value => self::isExpired($subscription->grace_period_ends_at),
            FacilitySubscriptionStatus::ACTIVE->value => self::isExpired($subscription->current_period_ends_at),
            default => false,
        };
    }

    private static function isExpired(mixed $value): bool
    {
        return $value instanceof DateTimeInterface && $value < now();
    }

    private static function accessState(string $status, bool $isExpired, bool $accessEnabled): string
    {
        if ($accessEnabled) {
            return 'enabled';
        }

        if ($isExpired && FacilitySubscriptionStatus::allowsAccess($status)) {
            return 'expired';
        }

        return in_array($status, [
            FacilitySubscriptionStatus::PAST_DUE->value,
            FacilitySubscriptionStatus::SUSPENDED->value,
            FacilitySubscriptionStatus::CANCELLED->value,
        ], true) ? 'restricted' : 'pending';
    }
}

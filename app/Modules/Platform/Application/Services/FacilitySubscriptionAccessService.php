<?php

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\ValueObjects\FacilitySubscriptionStatus;
use App\Modules\Platform\Infrastructure\Models\FacilitySubscriptionModel;
use DateTimeInterface;

class FacilitySubscriptionAccessService
{
    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
        private readonly PlanCatalogService $planCatalogService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function currentAccessSummary(): array
    {
        $facility = $this->scopeContext->facility();
        $facilityId = $this->scopeContext->facilityId();

        if ($facilityId === null) {
            return $this->currentSummary(
                accessEnabled: false,
                accessState: 'facility_scope_required',
                code: 'FACILITY_SCOPE_REQUIRED',
                message: 'Select a facility before using subscription-gated setup.',
                facility: $facility,
                subscription: null,
                grantedEntitlements: [],
            );
        }

        $subscription = FacilitySubscriptionModel::query()
            ->with('plan.entitlements')
            ->where('facility_id', $facilityId)
            ->first();

        if (! $subscription) {
            return $this->currentSummary(
                accessEnabled: false,
                accessState: 'not_configured',
                code: 'FACILITY_SUBSCRIPTION_REQUIRED',
                message: 'This facility does not have a configured subscription plan.',
                facility: $facility,
                subscription: null,
                grantedEntitlements: [],
            );
        }

        $grantedEntitlements = $this->grantedEntitlements($subscription);
        $status = (string) $subscription->status;
        $isExpired = $this->isSubscriptionExpired($subscription);
        $accessEnabled = FacilitySubscriptionStatus::allowsAccess($status) && ! $isExpired;

        return $this->currentSummary(
            accessEnabled: $accessEnabled,
            accessState: $this->accessState($status, $isExpired, $accessEnabled),
            code: $accessEnabled ? null : ($isExpired ? 'FACILITY_SUBSCRIPTION_EXPIRED' : 'FACILITY_SUBSCRIPTION_RESTRICTED'),
            message: $accessEnabled ? null : 'This facility subscription is not active for all plan services.',
            facility: $facility,
            subscription: $this->subscriptionSummary($subscription),
            grantedEntitlements: $grantedEntitlements,
        );
    }

    /**
     * @param  array<int, string>  $requiredEntitlements
     * @return array<string, mixed>
     */
    public function evaluate(array $requiredEntitlements): array
    {
        $requiredEntitlements = $this->normalizeEntitlements($requiredEntitlements);
        $facility = $this->scopeContext->facility();
        $facilityId = $this->scopeContext->facilityId();

        if ($requiredEntitlements === []) {
            return $this->allowed($facility, null, [], []);
        }

        if ($facilityId === null) {
            return $this->denied(
                code: 'FACILITY_SCOPE_REQUIRED',
                message: 'Select a facility before using this service.',
                facility: $facility,
                subscription: null,
                requiredEntitlements: $requiredEntitlements,
                grantedEntitlements: [],
            );
        }

        $subscription = FacilitySubscriptionModel::query()
            ->with('plan.entitlements')
            ->where('facility_id', $facilityId)
            ->first();

        if (! $subscription) {
            return $this->denied(
                code: 'FACILITY_SUBSCRIPTION_REQUIRED',
                message: 'This facility does not have a configured subscription plan.',
                facility: $facility,
                subscription: null,
                requiredEntitlements: $requiredEntitlements,
                grantedEntitlements: [],
            );
        }

        $grantedEntitlements = $this->grantedEntitlements($subscription);

        if (! FacilitySubscriptionStatus::allowsAccess((string) $subscription->status)) {
            return $this->denied(
                code: 'FACILITY_SUBSCRIPTION_RESTRICTED',
                message: 'This facility subscription is not active for the requested service.',
                facility: $facility,
                subscription: $this->subscriptionSummary($subscription),
                requiredEntitlements: $requiredEntitlements,
                grantedEntitlements: $grantedEntitlements,
            );
        }

        if ($this->isSubscriptionExpired($subscription)) {
            return $this->denied(
                code: 'FACILITY_SUBSCRIPTION_EXPIRED',
                message: 'This facility subscription period has expired.',
                facility: $facility,
                subscription: $this->subscriptionSummary($subscription),
                requiredEntitlements: $requiredEntitlements,
                grantedEntitlements: $grantedEntitlements,
            );
        }

        $missing = array_values(array_diff($requiredEntitlements, $grantedEntitlements));
        if ($missing !== []) {
            return $this->denied(
                code: 'FACILITY_ENTITLEMENT_REQUIRED',
                message: 'This facility subscription plan does not include the requested service.',
                facility: $facility,
                subscription: $this->subscriptionSummary($subscription),
                requiredEntitlements: $requiredEntitlements,
                grantedEntitlements: $grantedEntitlements,
                missingEntitlements: $missing,
            );
        }

        return $this->allowed(
            facility: $facility,
            subscription: $this->subscriptionSummary($subscription),
            requiredEntitlements: $requiredEntitlements,
            grantedEntitlements: $grantedEntitlements,
        );
    }

    /**
     * Resolve the quota limits for the facility's current subscription plan
     * from the 2027 plan catalog. Returns an empty array when the facility,
     * subscription, or plan cannot be resolved.
     *
     * @return array<string, int|null>
     */
    public function limitsForFacility(): array
    {
        $facilityId = $this->scopeContext->facilityId();

        if ($facilityId === null) {
            return [];
        }

        $subscription = FacilitySubscriptionModel::query()
            ->with('plan')
            ->where('facility_id', $facilityId)
            ->first();

        if (! $subscription || ! $subscription->plan) {
            return [];
        }

        return $this->planCatalogService->limitsForPlan((string) $subscription->plan->code);
    }

    /**
     * Allow access when the facility plan includes at least one of the given entitlements (OR).
     * Used for front-office flows that are valid on either a full admissions SKU or a scheduling-tier plan.
     *
     * @param  array<int, string>  $alternativeEntitlements
     * @return array<string, mixed>
     */
    public function evaluateAny(array $alternativeEntitlements): array
    {
        $alternatives = $this->normalizeEntitlements($alternativeEntitlements);

        if ($alternatives === []) {
            return $this->allowed($this->scopeContext->facility(), null, [], []);
        }

        $facility = $this->scopeContext->facility();
        $facilityId = $this->scopeContext->facilityId();

        if ($facilityId === null) {
            return $this->denied(
                code: 'FACILITY_SCOPE_REQUIRED',
                message: 'Select a facility before using this service.',
                facility: $facility,
                subscription: null,
                requiredEntitlements: $alternatives,
                grantedEntitlements: [],
            );
        }

        $subscription = FacilitySubscriptionModel::query()
            ->with('plan.entitlements')
            ->where('facility_id', $facilityId)
            ->first();

        if (! $subscription) {
            return $this->denied(
                code: 'FACILITY_SUBSCRIPTION_REQUIRED',
                message: 'This facility does not have a configured subscription plan.',
                facility: $facility,
                subscription: null,
                requiredEntitlements: $alternatives,
                grantedEntitlements: [],
            );
        }

        $grantedEntitlements = $this->grantedEntitlements($subscription);

        if (! FacilitySubscriptionStatus::allowsAccess((string) $subscription->status)) {
            return $this->denied(
                code: 'FACILITY_SUBSCRIPTION_RESTRICTED',
                message: 'This facility subscription is not active for the requested service.',
                facility: $facility,
                subscription: $this->subscriptionSummary($subscription),
                requiredEntitlements: $alternatives,
                grantedEntitlements: $grantedEntitlements,
                missingEntitlements: array_values(array_diff($alternatives, $grantedEntitlements)),
            );
        }

        if ($this->isSubscriptionExpired($subscription)) {
            return $this->denied(
                code: 'FACILITY_SUBSCRIPTION_EXPIRED',
                message: 'This facility subscription period has expired.',
                facility: $facility,
                subscription: $this->subscriptionSummary($subscription),
                requiredEntitlements: $alternatives,
                grantedEntitlements: $grantedEntitlements,
                missingEntitlements: array_values(array_diff($alternatives, $grantedEntitlements)),
            );
        }

        foreach ($alternatives as $key) {
            if (in_array($key, $grantedEntitlements, true)) {
                return $this->allowed(
                    facility: $facility,
                    subscription: $this->subscriptionSummary($subscription),
                    requiredEntitlements: [$key],
                    grantedEntitlements: $grantedEntitlements,
                );
            }
        }

        return $this->denied(
            code: 'FACILITY_ENTITLEMENT_REQUIRED',
            message: 'This facility subscription plan does not include the requested service.',
            facility: $facility,
            subscription: $this->subscriptionSummary($subscription),
            requiredEntitlements: $alternatives,
            grantedEntitlements: $grantedEntitlements,
            missingEntitlements: array_values(array_diff($alternatives, $grantedEntitlements)),
        );
    }

    /**
     * @param  array<int, string>  $entitlements
     * @return array<int, string>
     */
    private function normalizeEntitlements(array $entitlements): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $entitlement): string => strtolower(trim($entitlement)),
            $entitlements,
        ))));
    }

    /**
     * @return array<int, string>
     */
    private function grantedEntitlements(FacilitySubscriptionModel $subscription): array
    {
        return $subscription->plan
            ? $subscription->plan->entitlements
                ->where('enabled', true)
                ->pluck('entitlement_key')
                ->map(static fn (mixed $key): string => strtolower(trim((string) $key)))
                ->filter()
                ->unique()
                ->values()
                ->all()
            : [];
    }

    private function isSubscriptionExpired(FacilitySubscriptionModel $subscription): bool
    {
        $status = (string) $subscription->status;

        if ($status === FacilitySubscriptionStatus::TRIAL->value) {
            return $this->isExpired($subscription->trial_ends_at);
        }

        if ($status === FacilitySubscriptionStatus::GRACE_PERIOD->value) {
            return $this->isExpired($subscription->grace_period_ends_at);
        }

        if ($status === FacilitySubscriptionStatus::ACTIVE->value) {
            return $this->isExpired($subscription->current_period_ends_at);
        }

        return false;
    }

    private function isExpired(mixed $value): bool
    {
        return $value instanceof DateTimeInterface && $value < now();
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionSummary(FacilitySubscriptionModel $subscription): array
    {
        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'planId' => $subscription->plan_id,
            'planCode' => $subscription->plan?->code,
            'planName' => $subscription->plan?->name,
            'currentPeriodEndsAt' => $subscription->current_period_ends_at?->format(DATE_ATOM),
            'gracePeriodEndsAt' => $subscription->grace_period_ends_at?->format(DATE_ATOM),
            'limits' => $subscription->plan
                ? $this->planCatalogService->limitsForPlan((string) $subscription->plan->code)
                : [],
        ];
    }

    private function accessState(string $status, bool $isExpired, bool $accessEnabled): string
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

    /**
     * @param  array<int, string>  $grantedEntitlements
     * @return array<string, mixed>
     */
    private function currentSummary(
        bool $accessEnabled,
        string $accessState,
        ?string $code,
        ?string $message,
        ?array $facility,
        ?array $subscription,
        array $grantedEntitlements,
    ): array {
        return [
            'accessEnabled' => $accessEnabled,
            'accessState' => $accessState,
            'code' => $code,
            'message' => $message,
            'facility' => $facility,
            'subscription' => $subscription,
            'entitlementKeys' => $grantedEntitlements,
            'grantedEntitlements' => $grantedEntitlements,
        ];
    }

    /**
     * @param  array<int, string>  $requiredEntitlements
     * @param  array<int, string>  $grantedEntitlements
     * @return array<string, mixed>
     */
    private function allowed(
        ?array $facility,
        ?array $subscription,
        array $requiredEntitlements,
        array $grantedEntitlements,
    ): array {
        return [
            'allowed' => true,
            'code' => null,
            'message' => null,
            'facility' => $facility,
            'subscription' => $subscription,
            'requiredEntitlements' => $requiredEntitlements,
            'grantedEntitlements' => $grantedEntitlements,
            'missingEntitlements' => [],
        ];
    }

    /**
     * @param  array<int, string>  $requiredEntitlements
     * @param  array<int, string>  $grantedEntitlements
     * @param  array<int, string>  $missingEntitlements
     * @return array<string, mixed>
     */
    private function denied(
        string $code,
        string $message,
        ?array $facility,
        ?array $subscription,
        array $requiredEntitlements,
        array $grantedEntitlements,
        array $missingEntitlements = [],
    ): array {
        return [
            'allowed' => false,
            'code' => $code,
            'message' => $message,
            'facility' => $facility,
            'subscription' => $subscription,
            'requiredEntitlements' => $requiredEntitlements,
            'grantedEntitlements' => $grantedEntitlements,
            'missingEntitlements' => $missingEntitlements,
        ];
    }
}

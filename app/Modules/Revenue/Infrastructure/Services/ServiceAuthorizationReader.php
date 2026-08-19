<?php

namespace App\Modules\Revenue\Infrastructure\Services;

use App\Modules\Revenue\Domain\Services\ChargeAuthorizationPolicyResolverInterface;
use App\Modules\Revenue\Domain\Services\ServiceAuthorizationReaderInterface;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\ServiceAuthorization;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;

class ServiceAuthorizationReader implements ServiceAuthorizationReaderInterface
{
    public function __construct(
        private readonly ChargeAuthorizationPolicyResolverInterface $policyResolver,
    ) {}

    public function isAuthorized(ChargeSourceKind $sourceKind, string $sourceId): bool
    {
        return $this->describe($sourceKind, $sourceId)->authorized;
    }

    public function describe(ChargeSourceKind $sourceKind, string $sourceId): ServiceAuthorization
    {
        $charge = $this->liveChargeQuery($sourceKind)
            ->where('source_workflow_id', $sourceId)
            ->first();

        return $charge === null
            ? ServiceAuthorization::notCharged()
            : $this->toAuthorization($charge);
    }

    public function describeMany(ChargeSourceKind $sourceKind, array $sourceIds): array
    {
        $result = array_fill_keys($sourceIds, ServiceAuthorization::notCharged());

        if ($sourceIds === []) {
            return $result;
        }

        $charges = $this->liveChargeQuery($sourceKind)
            ->whereIn('source_workflow_id', $sourceIds)
            ->get();

        foreach ($charges as $charge) {
            $result[(string) $charge->source_workflow_id] = $this->toAuthorization($charge);
        }

        return $result;
    }

    /**
     * A cancelled charge is not the current answer for its source — the
     * service may have been re-charged since, and the partial unique index
     * guarantees at most one live row.
     */
    private function liveChargeQuery(ChargeSourceKind $sourceKind)
    {
        return ServiceChargeModel::query()
            ->where('source_workflow_kind', $sourceKind->value)
            ->where('status', '!=', ServiceChargeStatus::CANCELLED->value);
    }

    private function toAuthorization(ServiceChargeModel $charge): ServiceAuthorization
    {
        $policy = $this->policyResolver->for($charge->payer_class);

        return new ServiceAuthorization(
            // Read from the charge's own status rather than re-deriving from
            // the policy: a waiver and an emergency override authorize a charge
            // without satisfying any payment requirement, and both must open
            // the gate.
            authorized: $charge->status->permitsFulfilment(),
            chargeId: (string) $charge->id,
            chargeNumber: (string) $charge->charge_number,
            status: $charge->status->value,
            basis: $charge->authorization_basis,
            amountDue: $charge->outstandingAmount(),
            amountPaid: $charge->allocatedAmount(),
            requirement: $policy->describeRequirement($charge),
        );
    }
}

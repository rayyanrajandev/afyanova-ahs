<?php

namespace App\Modules\Revenue\Domain\Services;

use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\ServiceAuthorization;

/**
 * The prepaid gate's read side, and the only thing other modules need in order
 * to honour it.
 *
 * Consumers ask about a clinical record — this appointment, this lab order —
 * and never about charges, payments or payer classes. That keeps the rule
 * enforceable from Reception, Laboratory or Pharmacy without any of them
 * taking a dependency on how money works.
 */
interface ServiceAuthorizationReaderInterface
{
    public function isAuthorized(ChargeSourceKind $sourceKind, string $sourceId): bool;

    public function describe(ChargeSourceKind $sourceKind, string $sourceId): ServiceAuthorization;

    /**
     * Bulk variant for queues: one query for a whole list rather than one per
     * row.
     *
     * @param  list<string>  $sourceIds
     * @return array<string, ServiceAuthorization> keyed by source id
     */
    public function describeMany(ChargeSourceKind $sourceKind, array $sourceIds): array;
}

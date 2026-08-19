<?php

namespace App\Modules\Revenue\Domain\Events;

use App\Modules\Revenue\Domain\ValueObjects\AuthorizationBasis;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;

/**
 * A charge has been cleared, by whatever basis.
 *
 * Revenue announces this and does not care who listens. The alternative —
 * having the payment use case reach into Appointment to move a patient along —
 * would make the ledger depend on every workflow it funds, which is the
 * coupling that made the retired billing module impossible to remove.
 */
final readonly class ServiceChargeAuthorized
{
    public function __construct(
        public string $serviceChargeId,
        public string $patientId,
        public ChargeSourceKind $sourceKind,
        public ?string $sourceId,
        public AuthorizationBasis $basis,
        public ?int $actorUserId = null,
    ) {}
}

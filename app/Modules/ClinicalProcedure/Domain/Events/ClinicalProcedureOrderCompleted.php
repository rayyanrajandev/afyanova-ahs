<?php

namespace App\Modules\ClinicalProcedure\Domain\Events;

class ClinicalProcedureOrderCompleted
{
    public function __construct(
        public readonly string $clinicalProcedureOrderId,
        public readonly string $patientId,
        public readonly ?string $appointmentId,
        public readonly ?int $orderedByUserId,
        public readonly ?int $actorId,
        public readonly ?string $facilityId = null,
    ) {}
}

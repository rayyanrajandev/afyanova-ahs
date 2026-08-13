<?php

namespace App\Modules\MedicalRecord\Domain\Events;

class MedicalRecordHandoffCancelled
{
    public function __construct(
        public readonly string $medicalRecordId,
        public readonly int $initiatorUserId,
    ) {}
}

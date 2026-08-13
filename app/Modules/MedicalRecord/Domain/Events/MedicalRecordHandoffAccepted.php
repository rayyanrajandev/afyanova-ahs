<?php

namespace App\Modules\MedicalRecord\Domain\Events;

class MedicalRecordHandoffAccepted
{
    public function __construct(
        public readonly string $medicalRecordId,
        public readonly string $recordNumber,
        public readonly int $newOwnerUserId,
        public readonly string $newOwnerName,
        public readonly int $previousOwnerUserId,
    ) {}
}

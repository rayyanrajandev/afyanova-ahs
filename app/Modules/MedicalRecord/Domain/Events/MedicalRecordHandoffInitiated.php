<?php

namespace App\Modules\MedicalRecord\Domain\Events;

class MedicalRecordHandoffInitiated
{
    public function __construct(
        public readonly string $medicalRecordId,
        public readonly string $recordNumber,
        public readonly int $targetUserId,
        public readonly int $initiatorUserId,
        public readonly string $initiatorName,
        public readonly ?string $note,
        public readonly string $patientId,
    ) {}
}

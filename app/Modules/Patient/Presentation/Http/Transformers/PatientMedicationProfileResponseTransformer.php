<?php

namespace App\Modules\Patient\Presentation\Http\Transformers;

class PatientMedicationProfileResponseTransformer
{
    public static function transform(array $record): array
    {
        return [
            'id' => $record['id'] ?? null,
            'patientId' => $record['patient_id'] ?? null,
            'medicationCode' => $record['medication_code'] ?? null,
            'medicationName' => $record['medication_name'] ?? null,
            'dose' => $record['dose'] ?? null,
            'route' => $record['route'] ?? null,
            'frequency' => $record['frequency'] ?? null,
            'source' => $record['source'] ?? null,
            'status' => $record['status'] ?? null,
            'startedAt' => $record['started_at'] ?? null,
            'stoppedAt' => $record['stopped_at'] ?? null,
            'indication' => $record['indication'] ?? null,
            'notes' => $record['notes'] ?? null,
            'lastReconciledAt' => $record['last_reconciled_at'] ?? null,
            'reconciliationNote' => $record['reconciliation_note'] ?? null,
            'createdAt' => $record['created_at'] ?? null,
            'updatedAt' => $record['updated_at'] ?? null,
        ];
    }
}

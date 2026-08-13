<?php

namespace App\Modules\MedicalRecord\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class MedicalRecordAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'medicalRecordId' => $log['medical_record_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log, [
            'medical-record.document.pdf.downloaded' => 'PDF Downloaded',
        ]);
    }
}

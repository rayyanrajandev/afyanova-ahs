<?php

namespace App\Modules\Patient\Presentation\Http\Transformers;

class PatientActivityFeedEventResponseTransformer
{
    public static function transform(array $log): array
    {
        $auditLog = PatientAuditLogResponseTransformer::transform($log);

        return [
            'id' => $auditLog['id'] ?? null,
            'patientId' => $auditLog['patientId'] ?? null,
            'action' => $auditLog['action'] ?? null,
            'actionLabel' => $auditLog['actionLabel'] ?? null,
            'actorId' => $auditLog['actorId'] ?? null,
            'actorType' => $auditLog['actorType'] ?? null,
            'actor' => $auditLog['actor'] ?? null,
            'metadata' => $auditLog['metadata'] ?? [],
            'occurredAt' => $auditLog['createdAt'] ?? null,
        ];
    }
}

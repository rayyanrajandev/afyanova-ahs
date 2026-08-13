<?php

namespace App\Modules\Encounter\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class EncounterAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'encounterId' => $log['encounter_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log, [
            'encounter.opened' => 'Encounter Opened',
            'encounter.status.updated' => 'Encounter Status Updated',
            'encounter.closed' => 'Encounter Closed',
            'encounter.reopened' => 'Encounter Reopened',
            'encounter.document.pdf.downloaded' => 'Chart Packet PDF Downloaded',
            'encounter.clinical-document.uploaded' => 'Clinical Attachment Uploaded',
            'encounter.clinical-document.updated' => 'Clinical Attachment Updated',
            'encounter.clinical-document.status.updated' => 'Clinical Attachment Status Updated',
            'encounter.clinical-document.downloaded' => 'Clinical Attachment Downloaded',
        ]);
    }
}

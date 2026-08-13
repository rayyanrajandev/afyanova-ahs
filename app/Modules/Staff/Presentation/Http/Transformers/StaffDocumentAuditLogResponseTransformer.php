<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class StaffDocumentAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'staffDocumentId' => $log['staff_document_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log, [
            'staff-document.created' => 'Document Uploaded',
            'staff-document.updated' => 'Document Updated',
            'staff-document.verification.updated' => 'Verification Updated',
            'staff-document.status.updated' => 'Status Updated',
        ]);
    }
}


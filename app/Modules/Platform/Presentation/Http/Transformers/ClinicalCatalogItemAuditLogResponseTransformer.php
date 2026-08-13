<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class ClinicalCatalogItemAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'clinicalCatalogItemId' => $log['platform_clinical_catalog_item_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log);
    }
}

<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class ClinicalPrivilegeCatalogAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'privilegeCatalogId' => $log['privilege_catalog_id'] ?? null,
            'tenantId' => $log['tenant_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log, [
            'privilege-catalog.created' => 'Privilege Catalog Created',
            'privilege-catalog.updated' => 'Privilege Catalog Updated',
            'privilege-catalog.status.updated' => 'Privilege Catalog Status Updated',
        ]);
    }
}

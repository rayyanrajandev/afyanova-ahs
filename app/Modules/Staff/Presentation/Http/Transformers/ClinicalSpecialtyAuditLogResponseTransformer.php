<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class ClinicalSpecialtyAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'specialtyId' => $log['specialty_id'] ?? null,
            'tenantId' => $log['tenant_id'] ?? null,
            'staffProfileId' => $log['staff_profile_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log, [
            'specialty.created' => 'Specialty Created',
            'specialty.updated' => 'Specialty Updated',
            'specialty.status.updated' => 'Specialty Status Updated',
            'staff-specialty.assignment.synced' => 'Staff Specialty Assignment Synced',
        ]);
    }
}


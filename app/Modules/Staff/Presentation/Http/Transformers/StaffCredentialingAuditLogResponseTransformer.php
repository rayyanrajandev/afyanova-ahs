<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class StaffCredentialingAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'staffProfileId' => $log['staff_profile_id'] ?? null,
            'tenantId' => $log['tenant_id'] ?? null,
            'staffRegulatoryProfileId' => $log['staff_regulatory_profile_id'] ?? null,
            'staffProfessionalRegistrationId' => $log['staff_professional_registration_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log, [
            'staff-credentialing.regulatory-profile.created' => 'Regulatory Profile Created',
            'staff-credentialing.regulatory-profile.updated' => 'Regulatory Profile Updated',
            'staff-credentialing.registration.created' => 'Registration Added',
            'staff-credentialing.registration.updated' => 'Registration Updated',
            'staff-credentialing.registration.verification.updated' => 'Registration Verification Updated',
        ]);
    }
}

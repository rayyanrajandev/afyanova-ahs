<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

class StaffProfessionalRegistrationResponseTransformer
{
    public static function transform(array $registration): array
    {
        return [
            'id' => $registration['id'] ?? null,
            'staffProfileId' => $registration['staff_profile_id'] ?? null,
            'tenantId' => $registration['tenant_id'] ?? null,
            'staffRegulatoryProfileId' => $registration['staff_regulatory_profile_id'] ?? null,
            'regulatorCode' => $registration['regulator_code'] ?? null,
            'registrationCategory' => $registration['registration_category'] ?? null,
            'registrationNumber' => $registration['registration_number'] ?? null,
            'licenseNumber' => $registration['license_number'] ?? null,
            'registrationStatus' => $registration['registration_status'] ?? null,
            'licenseStatus' => $registration['license_status'] ?? null,
            'verificationStatus' => $registration['verification_status'] ?? null,
            'verificationReason' => $registration['verification_reason'] ?? null,
            'verificationNotes' => $registration['verification_notes'] ?? null,
            'verifiedAt' => $registration['verified_at'] ?? null,
            'verifiedByUserId' => $registration['verified_by_user_id'] ?? null,
            'issuedAt' => $registration['issued_at'] ?? null,
            'expiresAt' => $registration['expires_at'] ?? null,
            'renewalDueAt' => $registration['renewal_due_at'] ?? null,
            'cpdCycleStartAt' => $registration['cpd_cycle_start_at'] ?? null,
            'cpdCycleEndAt' => $registration['cpd_cycle_end_at'] ?? null,
            'cpdPointsRequired' => $registration['cpd_points_required'] ?? null,
            'cpdPointsEarned' => $registration['cpd_points_earned'] ?? null,
            'sourceDocumentId' => $registration['source_document_id'] ?? null,
            'sourceSystem' => $registration['source_system'] ?? null,
            'notes' => $registration['notes'] ?? null,
            'createdByUserId' => $registration['created_by_user_id'] ?? null,
            'updatedByUserId' => $registration['updated_by_user_id'] ?? null,
            'createdAt' => $registration['created_at'] ?? null,
            'updatedAt' => $registration['updated_at'] ?? null,
        ];
    }

    public static function transformSummary(array $registration): array
    {
        return [
            'id' => $registration['id'] ?? null,
            'regulatorCode' => $registration['regulator_code'] ?? null,
            'registrationNumber' => $registration['registration_number'] ?? null,
            'licenseNumber' => $registration['license_number'] ?? null,
            'registrationStatus' => $registration['registration_status'] ?? null,
            'licenseStatus' => $registration['license_status'] ?? null,
            'verificationStatus' => $registration['verification_status'] ?? null,
            'expiresAt' => $registration['expires_at'] ?? null,
        ];
    }
}

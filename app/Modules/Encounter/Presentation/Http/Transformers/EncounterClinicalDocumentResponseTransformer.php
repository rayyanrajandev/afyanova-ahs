<?php

namespace App\Modules\Encounter\Presentation\Http\Transformers;

class EncounterClinicalDocumentResponseTransformer
{
    public static function transform(array $document): array
    {
        return [
            'id' => $document['id'] ?? null,
            'encounterId' => $document['encounter_id'] ?? null,
            'patientId' => $document['patient_id'] ?? null,
            'tenantId' => $document['tenant_id'] ?? null,
            'facilityId' => $document['facility_id'] ?? null,
            'documentType' => $document['document_type'] ?? null,
            'title' => $document['title'] ?? null,
            'description' => $document['description'] ?? null,
            'originalFilename' => $document['original_filename'] ?? null,
            'mimeType' => $document['mime_type'] ?? null,
            'fileSizeBytes' => $document['file_size_bytes'] ?? null,
            'checksumSha256' => $document['checksum_sha256'] ?? null,
            'status' => $document['status'] ?? null,
            'statusReason' => $document['status_reason'] ?? null,
            'uploadedByUserId' => $document['uploaded_by_user_id'] ?? null,
            'createdAt' => $document['created_at'] ?? null,
            'updatedAt' => $document['updated_at'] ?? null,
        ];
    }
}

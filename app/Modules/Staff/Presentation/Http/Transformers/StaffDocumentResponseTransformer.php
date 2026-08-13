<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

class StaffDocumentResponseTransformer
{
    public static function transform(array $document): array
    {
        return [
            'id' => $document['id'] ?? null,
            'staffProfileId' => $document['staff_profile_id'] ?? null,
            'tenantId' => $document['tenant_id'] ?? null,
            'documentType' => $document['document_type'] ?? null,
            'title' => $document['title'] ?? null,
            'description' => $document['description'] ?? null,
            'originalFilename' => $document['original_filename'] ?? null,
            'mimeType' => $document['mime_type'] ?? null,
            'fileSizeBytes' => $document['file_size_bytes'] ?? null,
            'checksumSha256' => $document['checksum_sha256'] ?? null,
            'issuedAt' => $document['issued_at'] ?? null,
            'expiresAt' => $document['expires_at'] ?? null,
            'verificationStatus' => $document['verification_status'] ?? null,
            'verificationReason' => $document['verification_reason'] ?? null,
            'status' => $document['status'] ?? null,
            'statusReason' => $document['status_reason'] ?? null,
            'uploadedByUserId' => $document['uploaded_by_user_id'] ?? null,
            'verifiedByUserId' => $document['verified_by_user_id'] ?? null,
            'verifiedAt' => $document['verified_at'] ?? null,
            'createdAt' => $document['created_at'] ?? null,
            'updatedAt' => $document['updated_at'] ?? null,
        ];
    }
}


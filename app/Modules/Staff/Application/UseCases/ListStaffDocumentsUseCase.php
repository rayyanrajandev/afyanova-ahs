<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\StaffDocumentRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\StaffDocumentStatus;
use App\Modules\Staff\Domain\ValueObjects\StaffDocumentVerificationStatus;

class ListStaffDocumentsUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffDocumentRepositoryInterface $staffDocumentRepository,
    ) {}

    public function execute(string $staffProfileId, array $filters): ?array
    {
        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $documentType = isset($filters['documentType']) ? trim((string) $filters['documentType']) : null;
        $documentType = $documentType === '' ? null : $documentType;

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, StaffDocumentStatus::values(), true)) {
            $status = null;
        }

        $verificationStatus = isset($filters['verificationStatus']) ? trim((string) $filters['verificationStatus']) : null;
        if (! in_array($verificationStatus, StaffDocumentVerificationStatus::values(), true)) {
            $verificationStatus = null;
        }

        $expiresFrom = isset($filters['expiresFrom']) ? trim((string) $filters['expiresFrom']) : null;
        $expiresFrom = $expiresFrom === '' ? null : $expiresFrom;

        $expiresTo = isset($filters['expiresTo']) ? trim((string) $filters['expiresTo']) : null;
        $expiresTo = $expiresTo === '' ? null : $expiresTo;

        $sortMap = [
            'title' => 'title',
            'documentType' => 'document_type',
            'status' => 'status',
            'verificationStatus' => 'verification_status',
            'expiresAt' => 'expires_at',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'createdAt'] ?? 'created_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        return $this->staffDocumentRepository->searchByStaffProfileId(
            staffProfileId: $staffProfileId,
            query: $query,
            documentType: $documentType,
            status: $status,
            verificationStatus: $verificationStatus,
            expiresFrom: $expiresFrom,
            expiresTo: $expiresTo,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}


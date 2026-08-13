<?php

namespace App\Modules\MedicalRecord\Application\UseCases;

use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;
use Illuminate\Support\Str;

class ListMedicalRecordsUseCase
{
    public function __construct(private readonly MedicalRecordRepositoryInterface $medicalRecordRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = $filters['status'] ?? null;
        if (! in_array($status, MedicalRecordStatus::values(), true)) {
            $status = null;
        }

        $sortMap = [
            'recordNumber' => 'record_number',
            'encounterAt' => 'encounter_at',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];

        $sortBy = $filters['sortBy'] ?? 'encounterAt';
        $sortBy = $sortMap[$sortBy] ?? 'encounter_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' ? null : $patientId;
        if ($patientId !== null && ! Str::isUuid($patientId)) {
            $patientId = null;
        }

        $encounterId = isset($filters['encounterId']) ? trim((string) $filters['encounterId']) : null;
        $encounterId = $encounterId === '' ? null : $encounterId;
        if ($encounterId !== null && ! Str::isUuid($encounterId)) {
            $encounterId = null;
        }

        $appointmentId = isset($filters['appointmentId']) ? trim((string) $filters['appointmentId']) : null;
        $appointmentId = $appointmentId === '' ? null : $appointmentId;
        if ($appointmentId !== null && ! Str::isUuid($appointmentId)) {
            $appointmentId = null;
        }

        $appointmentReferralId = isset($filters['appointmentReferralId']) ? trim((string) $filters['appointmentReferralId']) : null;
        $appointmentReferralId = $appointmentReferralId === '' ? null : $appointmentReferralId;
        if ($appointmentReferralId !== null && ! Str::isUuid($appointmentReferralId)) {
            $appointmentReferralId = null;
        }

        $admissionId = isset($filters['admissionId']) ? trim((string) $filters['admissionId']) : null;
        $admissionId = $admissionId === '' ? null : $admissionId;
        if ($admissionId !== null && ! Str::isUuid($admissionId)) {
            $admissionId = null;
        }

        $theatreProcedureId = isset($filters['theatreProcedureId']) ? trim((string) $filters['theatreProcedureId']) : null;
        $theatreProcedureId = $theatreProcedureId === '' ? null : $theatreProcedureId;
        if ($theatreProcedureId !== null && ! Str::isUuid($theatreProcedureId)) {
            $theatreProcedureId = null;
        }

        $authorUserId = isset($filters['authorUserId']) ? (int) $filters['authorUserId'] : null;
        if ($authorUserId !== null && $authorUserId <= 0) {
            $authorUserId = null;
        }

        $recordType = isset($filters['recordType']) ? trim((string) $filters['recordType']) : null;
        $recordType = $recordType === '' ? null : $recordType;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->medicalRecordRepository->search(
            query: $query,
            patientId: $patientId,
            encounterId: $encounterId,
            appointmentId: $appointmentId,
            appointmentReferralId: $appointmentReferralId,
            admissionId: $admissionId,
            theatreProcedureId: $theatreProcedureId,
            authorUserId: $authorUserId,
            status: $status,
            recordType: $recordType,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}

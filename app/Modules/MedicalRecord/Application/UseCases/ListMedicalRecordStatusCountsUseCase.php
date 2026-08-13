<?php

namespace App\Modules\MedicalRecord\Application\UseCases;

use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use Illuminate\Support\Str;

class ListMedicalRecordStatusCountsUseCase
{
    public function __construct(private readonly MedicalRecordRepositoryInterface $medicalRecordRepository) {}

    public function execute(array $filters): array
    {
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

        $recordType = isset($filters['recordType']) ? trim((string) $filters['recordType']) : null;
        $recordType = $recordType === '' ? null : $recordType;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->medicalRecordRepository->statusCounts(
            query: $query,
            patientId: $patientId,
            encounterId: $encounterId,
            appointmentReferralId: $appointmentReferralId,
            admissionId: $admissionId,
            theatreProcedureId: $theatreProcedureId,
            recordType: $recordType,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
    }
}

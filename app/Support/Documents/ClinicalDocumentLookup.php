<?php

namespace App\Support\Documents;

use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordSignerAttestationModel;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordVersionModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use Illuminate\Database\Eloquent\Builder;

class ClinicalDocumentLookup
{
    public function __construct(private readonly DocumentContextLookup $documentContextLookup) {}

    /**
     * @return array<string, mixed>|null
     */
    public function diagnosisSummary(mixed $diagnosisCode): ?array
    {
        $normalizedCode = strtoupper(trim((string) $diagnosisCode));
        if ($normalizedCode === '') {
            return null;
        }

        $diagnosis = ClinicalCatalogItemModel::query()
            ->where('catalog_type', 'diagnosis_code')
            ->where('code', $normalizedCode)
            ->first();

        return [
            'code' => $normalizedCode,
            'name' => $diagnosis?->name,
            'category' => $diagnosis?->category,
            'description' => $diagnosis?->description,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function medicalRecordAttestations(string $medicalRecordId, int $limit = 10): array
    {
        $normalizedId = trim($medicalRecordId);
        if ($normalizedId === '') {
            return [];
        }

        return MedicalRecordSignerAttestationModel::query()
            ->where('medical_record_id', $normalizedId)
            ->orderByDesc('attested_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (MedicalRecordSignerAttestationModel $attestation): array => [
                'id' => (string) $attestation->id,
                'attestationNote' => $attestation->attestation_note,
                'attestedAt' => $attestation->attested_at?->toISOString(),
                'attestedBy' => $this->documentContextLookup->userSummary($attestation->attested_by_user_id),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function medicalRecordVersionSummary(string $medicalRecordId): array
    {
        $normalizedId = trim($medicalRecordId);
        if ($normalizedId === '') {
            return [
                'count' => 0,
                'latestVersionNumber' => null,
                'latestVersionCreatedAt' => null,
                'latestVersionCreatedBy' => null,
                'latestChangedFieldCount' => 0,
            ];
        }

        $query = MedicalRecordVersionModel::query()
            ->where('medical_record_id', $normalizedId);

        $count = (clone $query)->count();
        $latest = $query
            ->orderByDesc('version_number')
            ->orderByDesc('created_at')
            ->first();

        return [
            'count' => $count,
            'latestVersionNumber' => $latest?->version_number,
            'latestVersionCreatedAt' => $latest?->created_at?->toISOString(),
            'latestVersionCreatedBy' => $this->documentContextLookup->userSummary($latest?->created_by_user_id),
            'latestChangedFieldCount' => is_array($latest?->changed_fields)
                ? count($latest->changed_fields)
                : 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function encounterLaboratoryOrders(
        mixed $patientId,
        mixed $appointmentId,
        mixed $admissionId,
        int $limit = 6,
        ?string $encounterId = null,
    ): array {
        $patientId = $this->normalizedString($patientId);
        if ($patientId === null) {
            return [];
        }

        $query = LaboratoryOrderModel::query();
        $this->applyEncounterScope(
            $query,
            $patientId,
            $this->normalizedString($appointmentId),
            $this->normalizedString($admissionId),
            $this->normalizedString($encounterId),
        );

        return $query
            ->orderByDesc('ordered_at')
            ->limit($limit)
            ->get()
            ->map(fn (LaboratoryOrderModel $order): array => [
                'id' => (string) $order->id,
                'orderNumber' => $order->order_number,
                'testName' => $order->test_name,
                'testCode' => $order->test_code,
                'priority' => $order->priority,
                'status' => $order->status,
                'orderedAt' => $order->ordered_at?->toISOString(),
                'resultedAt' => $order->resulted_at?->toISOString(),
                'resultSummary' => $order->result_summary,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function encounterPharmacyOrders(
        mixed $patientId,
        mixed $appointmentId,
        mixed $admissionId,
        int $limit = 6,
        ?string $encounterId = null,
    ): array {
        $patientId = $this->normalizedString($patientId);
        if ($patientId === null) {
            return [];
        }

        $query = PharmacyOrderModel::query();
        $this->applyEncounterScope(
            $query,
            $patientId,
            $this->normalizedString($appointmentId),
            $this->normalizedString($admissionId),
            $this->normalizedString($encounterId),
        );

        return $query
            ->orderByDesc('ordered_at')
            ->limit($limit)
            ->get()
            ->map(fn (PharmacyOrderModel $order): array => [
                'id' => (string) $order->id,
                'orderNumber' => $order->order_number,
                'medicationName' => $order->medication_name,
                'medicationCode' => $order->medication_code,
                'dosageInstruction' => $order->dosage_instruction,
                'quantityPrescribed' => $order->quantity_prescribed,
                'quantityDispensed' => $order->quantity_dispensed,
                'status' => $order->status,
                'orderedAt' => $order->ordered_at?->toISOString(),
                'dispensedAt' => $order->dispensed_at?->toISOString(),
                'dispensingNotes' => $order->dispensing_notes,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function encounterRadiologyOrders(
        mixed $patientId,
        mixed $appointmentId,
        mixed $admissionId,
        int $limit = 6,
        ?string $encounterId = null,
    ): array {
        $patientId = $this->normalizedString($patientId);
        if ($patientId === null) {
            return [];
        }

        $query = RadiologyOrderModel::query();
        $this->applyEncounterScope(
            $query,
            $patientId,
            $this->normalizedString($appointmentId),
            $this->normalizedString($admissionId),
            $this->normalizedString($encounterId),
        );

        return $query
            ->orderByDesc('ordered_at')
            ->limit($limit)
            ->get()
            ->map(fn (RadiologyOrderModel $order): array => [
                'id' => (string) $order->id,
                'orderNumber' => $order->order_number,
                'modality' => $order->modality,
                'studyDescription' => $order->study_description,
                'status' => $order->status,
                'orderedAt' => $order->ordered_at?->toISOString(),
                'scheduledFor' => $order->scheduled_for?->toISOString(),
                'completedAt' => $order->completed_at?->toISOString(),
                'reportSummary' => $order->report_summary,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function encounterTheatreProcedures(
        mixed $patientId,
        mixed $appointmentId,
        mixed $admissionId,
        int $limit = 6,
        ?string $encounterId = null,
    ): array {
        $patientId = $this->normalizedString($patientId);
        if ($patientId === null) {
            return [];
        }

        $query = TheatreProcedureModel::query();
        $this->applyEncounterScope(
            $query,
            $patientId,
            $this->normalizedString($appointmentId),
            $this->normalizedString($admissionId),
            $this->normalizedString($encounterId),
        );

        return $query
            ->orderByDesc('scheduled_at')
            ->limit($limit)
            ->get()
            ->map(fn (TheatreProcedureModel $procedure): array => [
                'id' => (string) $procedure->id,
                'procedureNumber' => $procedure->procedure_number,
                'procedureType' => $procedure->procedure_type,
                'procedureName' => $procedure->procedure_name,
                'theatreRoomName' => $procedure->theatre_room_name,
                'status' => $procedure->status,
                'scheduledAt' => $procedure->scheduled_at?->toISOString(),
                'completedAt' => $procedure->completed_at?->toISOString(),
                'notes' => $procedure->notes,
            ])
            ->values()
            ->all();
    }

    private function applyEncounterScope(
        Builder $query,
        string $patientId,
        ?string $appointmentId,
        ?string $admissionId,
        ?string $encounterId = null,
    ): void {
        $query->where('patient_id', $patientId);

        if ($encounterId !== null) {
            $query->where('encounter_id', $encounterId);

            return;
        }

        if ($appointmentId !== null) {
            $query->where('appointment_id', $appointmentId);
        }

        if ($admissionId !== null) {
            $query->where('admission_id', $admissionId);
        }
    }

    private function normalizedString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}

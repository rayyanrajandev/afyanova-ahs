<?php

namespace App\Modules\TheatreProcedure\Presentation\Http\Transformers;

use App\Modules\Platform\Application\Services\ClinicalCatalogRecipeStockConsumptionService;
use App\Support\ClinicalOrders\ClinicalCurrentCare;

class TheatreProcedureResponseTransformer
{
    public static function transform(array $procedure, bool $includeStockPrecheck = false): array
    {
        $patient = is_array($procedure['patient'] ?? null) ? $procedure['patient'] : [];
        $theatreRoomServicePoint = is_array($procedure['theatre_room_service_point'] ?? null)
            ? $procedure['theatre_room_service_point']
            : [];

        return [
            'id' => $procedure['id'] ?? null,
            'procedureNumber' => $procedure['procedure_number'] ?? null,
            'patientId' => $procedure['patient_id'] ?? null,
            'patientNumber' => $patient['patient_number'] ?? null,
            'patientLabel' => self::patientLabel($patient, $procedure['patient_id'] ?? null),
            'admissionId' => $procedure['admission_id'] ?? null,
            'appointmentId' => $procedure['appointment_id'] ?? null,
            'encounterId' => $procedure['encounter_id'] ?? null,
            'orderSessionId' => $procedure['clinical_order_session_id'] ?? null,
            'replacesOrderId' => $procedure['replaces_order_id'] ?? null,
            'addOnToOrderId' => $procedure['add_on_to_order_id'] ?? null,
            'theatreProcedureCatalogItemId' => $procedure['theatre_procedure_catalog_item_id'] ?? null,
            'procedureType' => $procedure['procedure_type'] ?? null,
            'procedureName' => $procedure['procedure_name'] ?? null,
            'operatingClinicianUserId' => $procedure['operating_clinician_user_id'] ?? null,
            'anesthetistUserId' => $procedure['anesthetist_user_id'] ?? null,
            'theatreRoomServicePointId' => $procedure['theatre_room_service_point_id'] ?? null,
            'theatreRoomName' => $procedure['theatre_room_name'] ?? ($theatreRoomServicePoint['name'] ?? null),
            'theatreRoomCode' => $theatreRoomServicePoint['code'] ?? null,
            'theatreRoomServicePointType' => $theatreRoomServicePoint['service_point_type'] ?? null,
            'theatreRoomLocation' => $theatreRoomServicePoint['location'] ?? null,
            'scheduledAt' => $procedure['scheduled_at'] ?? null,
            'startedAt' => $procedure['started_at'] ?? null,
            'completedAt' => $procedure['completed_at'] ?? null,
            'status' => $procedure['status'] ?? null,
            'entryState' => $procedure['entry_state'] ?? null,
            'signedAt' => $procedure['signed_at'] ?? null,
            'signedByUserId' => $procedure['signed_by_user_id'] ?? null,
            'statusReason' => $procedure['status_reason'] ?? null,
            'lifecycleReasonCode' => $procedure['lifecycle_reason_code'] ?? null,
            'enteredInErrorAt' => $procedure['entered_in_error_at'] ?? null,
            'enteredInErrorByUserId' => $procedure['entered_in_error_by_user_id'] ?? null,
            'lifecycleLockedAt' => $procedure['lifecycle_locked_at'] ?? null,
            'currentCare' => ClinicalCurrentCare::theatre($procedure),
            'stockPrecheck' => $includeStockPrecheck
                ? self::stockPrecheck($procedure)
                : null,
            'notes' => $procedure['notes'] ?? null,
            'createdAt' => $procedure['created_at'] ?? null,
            'updatedAt' => $procedure['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $procedure
     * @return array<string, mixed>
     */
    private static function stockPrecheck(array $procedure): array
    {
        static $cache = [];

        $catalogItemId = trim((string) ($procedure['theatre_procedure_catalog_item_id'] ?? ''));
        $cacheKey = 'theatre_procedure:'.($catalogItemId !== '' ? $catalogItemId : 'none');

        if (! array_key_exists($cacheKey, $cache)) {
            $cache[$cacheKey] = app(ClinicalCatalogRecipeStockConsumptionService::class)
                ->precheckForClinicalWork(
                    $catalogItemId !== '' ? $catalogItemId : null,
                    'theatre_procedure',
                );
        }

        return $cache[$cacheKey];
    }

    /**
     * @param array<string, mixed> $patient
     */
    private static function patientLabel(array $patient, mixed $fallbackPatientId): ?string
    {
        $segments = array_values(array_filter([
            trim((string) ($patient['first_name'] ?? '')),
            trim((string) ($patient['middle_name'] ?? '')),
            trim((string) ($patient['last_name'] ?? '')),
        ]));

        $name = trim(implode(' ', $segments));
        $patientNumber = trim((string) ($patient['patient_number'] ?? ''));

        if ($name !== '' && $patientNumber !== '') {
            return sprintf('%s (%s)', $name, $patientNumber);
        }

        if ($name !== '') {
            return $name;
        }

        if ($patientNumber !== '') {
            return $patientNumber;
        }

        $fallback = trim((string) $fallbackPatientId);

        return $fallback !== '' ? $fallback : null;
    }
}

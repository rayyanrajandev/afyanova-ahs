<?php

namespace App\Modules\Radiology\Presentation\Http\Transformers;

use App\Modules\Platform\Application\Services\ClinicalCatalogRecipeStockConsumptionService;
use App\Support\ClinicalOrders\ClinicalCurrentCare;

class RadiologyOrderResponseTransformer
{
    public static function transform(array $order, bool $includeStockPrecheck = false): array
    {
        return [
            'id' => $order['id'] ?? null,
            'orderNumber' => $order['order_number'] ?? null,
            'patientId' => $order['patient_id'] ?? null,
            'encounterId' => $order['encounter_id'] ?? null,
            'admissionId' => $order['admission_id'] ?? null,
            'appointmentId' => $order['appointment_id'] ?? null,
            'orderSessionId' => $order['clinical_order_session_id'] ?? null,
            'replacesOrderId' => $order['replaces_order_id'] ?? null,
            'addOnToOrderId' => $order['add_on_to_order_id'] ?? null,
            'orderedByUserId' => $order['ordered_by_user_id'] ?? null,
            'orderedAt' => $order['ordered_at'] ?? null,
            'radiologyProcedureCatalogItemId' => $order['radiology_procedure_catalog_item_id'] ?? null,
            'procedureCode' => $order['procedure_code'] ?? null,
            'modality' => $order['modality'] ?? null,
            'studyDescription' => $order['study_description'] ?? null,
            'clinicalIndication' => $order['clinical_indication'] ?? null,
            'scheduledFor' => $order['scheduled_for'] ?? null,
            'reportSummary' => $order['report_summary'] ?? null,
            'completedAt' => $order['completed_at'] ?? null,
            'status' => $order['status'] ?? null,
            'entryState' => $order['entry_state'] ?? null,
            'signedAt' => $order['signed_at'] ?? null,
            'signedByUserId' => $order['signed_by_user_id'] ?? null,
            'statusReason' => $order['status_reason'] ?? null,
            'lifecycleReasonCode' => $order['lifecycle_reason_code'] ?? null,
            'enteredInErrorAt' => $order['entered_in_error_at'] ?? null,
            'enteredInErrorByUserId' => $order['entered_in_error_by_user_id'] ?? null,
            'lifecycleLockedAt' => $order['lifecycle_locked_at'] ?? null,
            'currentCare' => ClinicalCurrentCare::radiology($order),
            'stockPrecheck' => $includeStockPrecheck
                ? self::stockPrecheck($order)
                : null,
            'createdAt' => $order['created_at'] ?? null,
            'updatedAt' => $order['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private static function stockPrecheck(array $order): array
    {
        static $cache = [];

        $catalogItemId = trim((string) ($order['radiology_procedure_catalog_item_id'] ?? ''));
        $cacheKey = 'radiology_procedure:'.($catalogItemId !== '' ? $catalogItemId : 'none');

        if (! array_key_exists($cacheKey, $cache)) {
            $cache[$cacheKey] = app(ClinicalCatalogRecipeStockConsumptionService::class)
                ->precheckForClinicalWork(
                    $catalogItemId !== '' ? $catalogItemId : null,
                    'radiology_procedure',
                );
        }

        return $cache[$cacheKey];
    }
}

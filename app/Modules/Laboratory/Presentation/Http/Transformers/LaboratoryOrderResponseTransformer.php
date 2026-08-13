<?php

namespace App\Modules\Laboratory\Presentation\Http\Transformers;

use App\Modules\Platform\Application\Services\ClinicalCatalogRecipeStockConsumptionService;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Support\ClinicalOrders\ClinicalCurrentCare;

class LaboratoryOrderResponseTransformer
{
    public static function transform(array $order, bool $includeStockPrecheck = false): array
    {
        $catalog = self::loadCatalogItem($order['lab_test_catalog_item_id'] ?? null);

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
            'labTestCatalogItemId' => $order['lab_test_catalog_item_id'] ?? null,
            'testCode' => $order['test_code'] ?? null,
            'testName' => $order['test_name'] ?? null,
            'priority' => $order['priority'] ?? null,
            'specimenType' => $order['specimen_type'] ?? null,
            'clinicalNotes' => $order['clinical_notes'] ?? null,
            'resultSummary' => $order['result_summary'] ?? null,
            'resultParameters' => $order['result_parameters'] ?? null,
            'catalogUnit' => $catalog['unit'],
            'catalogParameters' => $catalog['parameters'],
            'catalogResultTemplate' => $catalog['resultTemplate'],
            'resultedAt' => $order['resulted_at'] ?? null,
            'verifiedAt' => $order['verified_at'] ?? null,
            'verifiedByUserId' => $order['verified_by_user_id'] ?? null,
            'verificationNote' => $order['verification_note'] ?? null,
            'status' => $order['status'] ?? null,
            'entryState' => $order['entry_state'] ?? null,
            'signedAt' => $order['signed_at'] ?? null,
            'signedByUserId' => $order['signed_by_user_id'] ?? null,
            'statusReason' => $order['status_reason'] ?? null,
            'lifecycleReasonCode' => $order['lifecycle_reason_code'] ?? null,
            'enteredInErrorAt' => $order['entered_in_error_at'] ?? null,
            'enteredInErrorByUserId' => $order['entered_in_error_by_user_id'] ?? null,
            'lifecycleLockedAt' => $order['lifecycle_locked_at'] ?? null,
            'currentCare' => ClinicalCurrentCare::laboratory($order),
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
    /**
     * @param  array<string, mixed>  $order
     * @return array{unit: string|null, parameters: array<int, mixed>}
     */
    private static function loadCatalogItem(?string $catalogItemId): array
    {
        if ($catalogItemId === null || trim($catalogItemId) === '') {
            return ['unit' => null, 'parameters' => [], 'resultTemplate' => null];
        }

        $item = ClinicalCatalogItemModel::query()
            ->where('catalog_type', ClinicalCatalogType::LAB_TEST->value)
            ->find($catalogItemId);

        if (! $item) {
            return ['unit' => null, 'parameters' => [], 'resultTemplate' => null];
        }

        $metadata = $item->metadata ?? [];

        return [
            'unit' => $item->unit,
            'parameters' => $metadata['parameters'] ?? [],
            'resultTemplate' => $metadata['resultTemplate'] ?? null,
        ];
    }

    private static function stockPrecheck(array $order): array
    {
        static $cache = [];

        $catalogItemId = trim((string) ($order['lab_test_catalog_item_id'] ?? ''));
        $cacheKey = 'lab_test:'.($catalogItemId !== '' ? $catalogItemId : 'none');

        if (! array_key_exists($cacheKey, $cache)) {
            $cache[$cacheKey] = app(ClinicalCatalogRecipeStockConsumptionService::class)
                ->precheckForClinicalWork(
                    $catalogItemId !== '' ? $catalogItemId : null,
                    'lab_test',
                );
        }

        return $cache[$cacheKey];
    }
}

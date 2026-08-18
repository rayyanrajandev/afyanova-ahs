<?php

namespace App\Modules\Pharmacy\Presentation\Http\Transformers;

use App\Modules\Platform\Application\Support\ClinicalCatalogBillingLinkEnricher;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Support\ClinicalOrders\ClinicalCurrentCare;

class PharmacyOrderResponseTransformer
{
    public static function transform(array $order): array
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
            'approvedMedicineCatalogItemId' => $order['approved_medicine_catalog_item_id'] ?? null,
            'medicationCode' => $order['medication_code'] ?? null,
            'medicationName' => $order['medication_name'] ?? null,
            'dosageInstruction' => $order['dosage_instruction'] ?? null,
            'doseQuantity' => $order['dose_quantity'] ?? null,
            'doseUnit' => $order['dose_unit'] ?? null,
            'route' => $order['route'] ?? null,
            'frequency' => $order['frequency'] ?? null,
            'durationValue' => $order['duration_value'] ?? null,
            'durationUnit' => $order['duration_unit'] ?? null,
            'infusionRateValue' => $order['infusion_rate_value'] ?? null,
            'infusionRateUnit' => $order['infusion_rate_unit'] ?? null,
            'infusionDurationValue' => $order['infusion_duration_value'] ?? null,
            'infusionDurationUnit' => $order['infusion_duration_unit'] ?? null,
            'clinicalIndication' => $order['clinical_indication'] ?? null,
            'quantityPrescribed' => $order['quantity_prescribed'] ?? null,
            // Price was only ever known to the client that placed the order —
            // it computed unitPrice x quantity locally and the API returned
            // nothing, so the figure vanished from "Prescribed Medications" the
            // moment the page reloaded. Resolved here from the same billing link
            // the prescribing catalog reads, so it survives a refresh.
            'unitPrice' => self::unitPrice($order),
            'totalPrice' => self::totalPrice($order),
            'prescribedUnit' => $order['prescribed_unit'] ?? null,
            'quantityDispensed' => $order['quantity_dispensed'] ?? null,
            'dispensedUnit' => $order['dispensed_unit'] ?? null,
            'dispensingNotes' => $order['dispensing_notes'] ?? null,
            'dispensedAt' => $order['dispensed_at'] ?? null,
            // The counterpart to verifiedByUserId. The workspace needs it to
            // explain a refused sign-off rather than just report one.
            'dispensedByUserId' => $order['dispensed_by_user_id'] ?? null,
            'verifiedAt' => $order['verified_at'] ?? null,
            'verifiedByUserId' => $order['verified_by_user_id'] ?? null,
            'verificationNote' => $order['verification_note'] ?? null,
            'formularyDecisionStatus' => $order['formulary_decision_status'] ?? null,
            'formularyDecisionReason' => $order['formulary_decision_reason'] ?? null,
            'formularyReviewedAt' => $order['formulary_reviewed_at'] ?? null,
            'formularyReviewedByUserId' => $order['formulary_reviewed_by_user_id'] ?? null,
            'substitutionAllowed' => $order['substitution_allowed'] ?? null,
            'substitutionMade' => $order['substitution_made'] ?? null,
            'substitutedMedicationCode' => $order['substituted_medication_code'] ?? null,
            'substitutedMedicationName' => $order['substituted_medication_name'] ?? null,
            'substitutionReason' => $order['substitution_reason'] ?? null,
            'substitutionApprovedAt' => $order['substitution_approved_at'] ?? null,
            'substitutionApprovedByUserId' => $order['substitution_approved_by_user_id'] ?? null,
            'reconciliationStatus' => $order['reconciliation_status'] ?? null,
            'reconciliationDecision' => $order['reconciliation_decision'] ?? null,
            'reconciliationNote' => $order['reconciliation_note'] ?? null,
            'reconciledAt' => $order['reconciled_at'] ?? null,
            'reconciledByUserId' => $order['reconciled_by_user_id'] ?? null,
            'status' => $order['status'] ?? null,
            'entryState' => $order['entry_state'] ?? null,
            'signedAt' => $order['signed_at'] ?? null,
            'signedByUserId' => $order['signed_by_user_id'] ?? null,
            'statusReason' => $order['status_reason'] ?? null,
            'lifecycleReasonCode' => $order['lifecycle_reason_code'] ?? null,
            'enteredInErrorAt' => $order['entered_in_error_at'] ?? null,
            'enteredInErrorByUserId' => $order['entered_in_error_by_user_id'] ?? null,
            'lifecycleLockedAt' => $order['lifecycle_locked_at'] ?? null,
            'serviceRequestItemId' => $order['service_request_item_id'] ?? null,
            'currentCare' => ClinicalCurrentCare::pharmacy($order),
            'createdAt' => $order['created_at'] ?? null,
            'updatedAt' => $order['updated_at'] ?? null,
        ];
    }

    /**
     * Base price of the prescribed medicine, or null when the catalog item
     * carries no billing link. Null is rendered as "—" rather than a misleading
     * zero: an unpriced medicine is unknown, not free.
     *
     * @param  array<string, mixed>  $order
     */
    private static function unitPrice(array $order): ?float
    {
        $catalogItemId = trim((string) ($order['approved_medicine_catalog_item_id'] ?? ''));
        if ($catalogItemId === '') {
            return null;
        }

        static $cache = [];

        if (! array_key_exists($catalogItemId, $cache)) {
            $item = ClinicalCatalogItemModel::query()->find($catalogItemId);

            $basePrice = null;
            if ($item !== null) {
                $enriched = app(ClinicalCatalogBillingLinkEnricher::class)->enrich($item->toArray());
                $basePrice = $enriched['billing_link']['item']['basePrice'] ?? null;
            }

            $cache[$catalogItemId] = $basePrice === null ? null : (float) $basePrice;
        }

        return $cache[$catalogItemId];
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private static function totalPrice(array $order): ?float
    {
        $unitPrice = self::unitPrice($order);
        if ($unitPrice === null) {
            return null;
        }

        $quantity = (float) ($order['quantity_prescribed'] ?? 0);

        return $quantity > 0 ? $unitPrice * $quantity : $unitPrice;
    }
}

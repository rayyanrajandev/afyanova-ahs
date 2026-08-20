<?php

namespace App\Modules\Revenue\Application\Services;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Revenue\Application\UseCases\RaiseServiceChargeUseCase;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\Services\RevenueTelemetryRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\PayerClass;
use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryEvent;
use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryReason;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Raises a service charge for a radiology / imaging order.
 *
 * Enforces the prepaid rule for medical imaging: a charge is created the
 * moment the clinician places the order, before any scan is scheduled,
 * performed, or reported. The order remains pending payment until settled
 * by the cashier.
 *
 * Never throws into order placement. A missing tariff or unexpected payer
 * logs a warning and creates an unpriced/draft charge rather than failing
 * the clinician's diagnostic workflow.
 */
class RadiologyChargeRaiser
{
    public function __construct(
        private readonly RaiseServiceChargeUseCase $raiseServiceCharge,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
        private readonly RevenueTelemetryRecorderInterface $telemetry,
    ) {}

    /**
     * @param  array<string, mixed>  $order
     */
    public function raiseFor(array $order, ?int $actorUserId = null): ?ServiceChargeModel
    {
        try {
            return $this->raise($order, $actorUserId);
        } catch (Throwable $exception) {
            Log::warning('Unable to raise service charge for radiology order.', [
                'radiology_order_id' => $order['id'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            $this->telemetry->record(
                event: RevenueTelemetryEvent::CHARGE_NOT_RAISED,
                reason: RevenueTelemetryReason::EXCEPTION,
                sourceKind: ChargeSourceKind::RADIOLOGY_ORDER,
                sourceWorkflowId: isset($order['id']) ? (string) $order['id'] : null,
                patientId: isset($order['patient_id']) ? (string) $order['patient_id'] : null,
                actorUserId: $actorUserId,
                detail: $exception->getMessage(),
            );

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function raise(array $order, ?int $actorUserId): ?ServiceChargeModel
    {
        if (! (bool) config('revenue.prepaid_required_for.radiology_order', true)) {
            return null;
        }

        $orderId = trim((string) ($order['id'] ?? ''));
        $patientId = trim((string) ($order['patient_id'] ?? ''));

        if ($orderId === '' || $patientId === '') {
            return null;
        }

        $payerClass = PayerClass::fromFinancialCoverage(
            isset($order['financial_coverage_type'])
                ? (string) $order['financial_coverage_type']
                : null,
        );

        if (! $payerClass->isImplemented()) {
            Log::info('Radiology order left uncharged: payer class is not settled in this phase.', [
                'radiology_order_id' => $orderId,
                'payer_class' => $payerClass->value,
            ]);

            $this->telemetry->record(
                event: RevenueTelemetryEvent::CHARGE_NOT_RAISED,
                reason: RevenueTelemetryReason::PAYER_UNIMPLEMENTED,
                sourceKind: ChargeSourceKind::RADIOLOGY_ORDER,
                sourceWorkflowId: $orderId,
                patientId: $patientId,
                actorUserId: $actorUserId,
                detail: $payerClass->value,
            );

            return null;
        }

        $chargeableItem = $this->resolveChargeableItem($order);

        if ($chargeableItem === null) {
            Log::warning('Radiology order left uncharged: no chargeable item configured for procedure.', [
                'radiology_order_id' => $orderId,
                'procedure_code' => $order['procedure_code'] ?? null,
                'catalog_item_id' => $order['radiology_procedure_catalog_item_id'] ?? null,
            ]);

            $this->telemetry->record(
                event: RevenueTelemetryEvent::CHARGE_NOT_RAISED,
                reason: RevenueTelemetryReason::NO_ITEM,
                sourceKind: ChargeSourceKind::RADIOLOGY_ORDER,
                sourceWorkflowId: $orderId,
                patientId: $patientId,
                actorUserId: $actorUserId,
            );

            return null;
        }

        return $this->raiseServiceCharge->execute(
            patientId: $patientId,
            sourceKind: ChargeSourceKind::RADIOLOGY_ORDER,
            sourceId: $orderId,
            chargeableItemId: (string) $chargeableItem->id,
            description: (string) ($order['study_description'] ?? $chargeableItem->name),
            quantity: 1.0,
            encounterId: isset($order['encounter_id']) ? (string) $order['encounter_id'] : null,
            appointmentId: isset($order['appointment_id']) ? (string) $order['appointment_id'] : null,
            admissionId: isset($order['admission_id']) ? (string) $order['admission_id'] : null,
            payerClass: $payerClass,
            unit: (string) ($chargeableItem->default_unit ?? 'exam'),
            actorUserId: $actorUserId,
        );
    }

    /**
     * Resolve the ChargeableItemModel for the ordered radiology procedure.
     *
     * @param  array<string, mixed>  $order
     */
    private function resolveChargeableItem(array $order): ?ChargeableItemModel
    {
        $catalogItemId = trim((string) ($order['radiology_procedure_catalog_item_id'] ?? ''));
        $procedureCode = strtoupper(trim((string) ($order['procedure_code'] ?? '')));
        $facilityId = $this->scopeContext->facilityId();

        // 1. Match by catalog item ID
        if ($catalogItemId !== '') {
            $item = ChargeableItemModel::query()
                ->where(function ($q) use ($catalogItemId): void {
                    $q->where('id', $catalogItemId)
                      ->orWhere('clinical_catalog_item_id', $catalogItemId);
                })
                ->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('facility_id')->orWhere('facility_id', $facilityId))
                ->orderByRaw('CASE WHEN facility_id IS NULL THEN 1 ELSE 0 END')
                ->first();

            if ($item !== null) {
                return $item;
            }
        }

        // 2. Match by procedure code
        if ($procedureCode !== '') {
            $item = ChargeableItemModel::query()
                ->whereRaw('UPPER(code) = ?', [$procedureCode])
                ->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('facility_id')->orWhere('facility_id', $facilityId))
                ->orderByRaw('CASE WHEN facility_id IS NULL THEN 1 ELSE 0 END')
                ->first();

            if ($item !== null) {
                return $item;
            }
        }

        return null;
    }
}

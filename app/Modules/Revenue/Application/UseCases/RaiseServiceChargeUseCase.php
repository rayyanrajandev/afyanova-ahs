<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Revenue\Application\Support\ServiceChargePricer;
use App\Modules\Revenue\Domain\Exceptions\PayerClassNotImplementedException;
use App\Modules\Revenue\Domain\Services\DocumentNumberAllocatorInterface;
use App\Modules\Revenue\Domain\Services\RevenueAuditRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Domain\ValueObjects\PayerClass;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\DB;

/**
 * Raise one charge for one billable service.
 *
 * This is where the prepaid rule starts: a charge exists *before* the service
 * does, and the service is not cleared for delivery until the charge is. The
 * caller is whichever module owns the clinical trigger — appointment creation
 * for a consultation, order signing for a lab test — so this use case knows
 * nothing about any of them beyond a source kind and an id.
 *
 * Idempotent per clinical source. Raising a charge twice for the same order is
 * the obvious way to double-bill a patient, and it is prevented in two places:
 * here, by returning the existing charge, and in the database, by a partial
 * unique index that does not depend on anyone remembering to call this.
 */
class RaiseServiceChargeUseCase
{
    public function __construct(
        private readonly ServiceChargePricer $pricer,
        private readonly DocumentNumberAllocatorInterface $numberAllocator,
        private readonly RevenueAuditRecorderInterface $auditRecorder,
        private readonly DefaultCurrencyResolverInterface $currencyResolver,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function execute(
        string $patientId,
        ChargeSourceKind $sourceKind,
        ?string $sourceId,
        string $chargeableItemId,
        string $description,
        float $quantity = 1.0,
        ?string $encounterId = null,
        ?string $appointmentId = null,
        ?string $admissionId = null,
        PayerClass $payerClass = PayerClass::SELF_PAY,
        ?string $payerContractId = null,
        ?float $discountPercent = null,
        ?string $discountReason = null,
        ?string $unit = null,
        ?int $actorUserId = null,
    ): ServiceChargeModel {
        if (! $payerClass->isImplemented()) {
            throw new PayerClassNotImplementedException($payerClass);
        }

        if ($sourceKind->requiresSourceReference() && ($sourceId === null || trim($sourceId) === '')) {
            throw new \InvalidArgumentException(sprintf(
                'A %s charge must reference the clinical record it pays for.',
                $sourceKind->value,
            ));
        }

        $existing = $this->findLiveChargeForSource($sourceKind, $sourceId);
        if ($existing !== null) {
            return $existing;
        }

        $tenantId = $this->scopeContext->tenantId();
        $facilityId = $this->scopeContext->facilityId();
        $currencyCode = $this->currencyResolver->resolve();

        $priced = $this->pricer->price(
            chargeableItemId: $chargeableItemId,
            quantity: $quantity,
            currencyCode: $currencyCode,
            tenantId: $tenantId,
            facilityId: $facilityId,
            payerContractId: $payerContractId,
            discountPercent: $discountPercent,
            discountReason: $discountReason,
        );

        // An unpriced charge is still a charge. It records that the service was
        // ordered and is owed for, and surfaces at the counter as something a
        // human has to price — rather than silently letting an unpriced service
        // through the gate for free, which is what a zero-amount charge would do.
        $status = $priced->isPriced()
            ? ServiceChargeStatus::PENDING_PAYMENT
            : ServiceChargeStatus::DRAFT;

        // Self-pay: the patient owes all of it. The split exists so an insurer
        // can later owe part without this table changing shape.
        $patientResponsibility = $priced->netAmount;
        $payerResponsibility = Money::zero($currencyCode);

        return DB::transaction(function () use (
            $patientId, $sourceKind, $sourceId, $description, $encounterId, $appointmentId,
            $admissionId, $payerClass, $payerContractId, $priced, $status, $unit,
            $patientResponsibility, $payerResponsibility, $currencyCode,
            $tenantId, $facilityId, $actorUserId
        ): ServiceChargeModel {
            $charge = ServiceChargeModel::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'charge_number' => $this->numberAllocator->allocate('service_charge', $tenantId, $facilityId),
                'patient_id' => $patientId,
                'encounter_id' => $encounterId,
                'appointment_id' => $appointmentId,
                'admission_id' => $admissionId,
                'source_workflow_kind' => $sourceKind->value,
                'source_workflow_id' => $sourceId,
                'chargeable_item_id' => $priced->chargeableItemId,
                'price_book_entry_id' => $priced->priceBookEntryId,
                'description' => $description,
                'unit' => $unit,
                'quantity' => $priced->quantity,
                'currency_code' => $currencyCode,
                'unit_price_minor' => $priced->unitPrice->minorUnits,
                'gross_amount_minor' => $priced->grossAmount->minorUnits,
                'discount_amount_minor' => $priced->discountAmount->minorUnits,
                'discount_reason' => $priced->discountReason,
                'tax_amount_minor' => $priced->taxAmount->minorUnits,
                'net_amount_minor' => $priced->netAmount->minorUnits,
                'payer_class' => $payerClass->value,
                'payer_contract_id' => $payerContractId,
                'patient_responsibility_minor' => $patientResponsibility->minorUnits,
                'payer_responsibility_minor' => $payerResponsibility->minorUnits,
                'allocated_amount_minor' => 0,
                'status' => $status->value,
                'pricing_status' => $priced->pricingStatus,
                'created_by_user_id' => $actorUserId,
            ]);

            $this->auditRecorder->record(
                entityType: 'service_charge',
                entityId: (string) $charge->id,
                action: 'raised',
                actorUserId: $actorUserId,
                amount: $priced->netAmount,
                after: [
                    'chargeNumber' => $charge->charge_number,
                    'sourceKind' => $sourceKind->value,
                    'sourceId' => $sourceId,
                    'status' => $status->value,
                    'pricingStatus' => $priced->pricingStatus,
                ],
            );

            return $charge;
        });
    }

    private function findLiveChargeForSource(ChargeSourceKind $sourceKind, ?string $sourceId): ?ServiceChargeModel
    {
        if ($sourceId === null || $sourceKind === ChargeSourceKind::MANUAL) {
            return null;
        }

        return ServiceChargeModel::query()
            ->where('source_workflow_kind', $sourceKind->value)
            ->where('source_workflow_id', $sourceId)
            ->where('status', '!=', ServiceChargeStatus::CANCELLED->value)
            ->first();
    }
}

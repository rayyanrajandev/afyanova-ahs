<?php

namespace App\Modules\Revenue\Application\Services;

use App\Modules\Appointment\Application\Support\ConsultationReviewPolicyResolver;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Revenue\Application\UseCases\RaiseServiceChargeUseCase;
use App\Modules\Revenue\Domain\Services\RevenueTelemetryRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\PayerClass;
use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryEvent;
use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryReason;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Raises the consultation charge for a visit.
 *
 * This is where the prepaid rule begins: the charge exists from the moment the
 * visit is booked, before anyone has been seen. It replaces
 * AutoCaptureConsultationFeeUseCase, which did the opposite — captured a fee on
 * the transition into in_consultation, i.e. once the service had already begun.
 *
 * Never throws into appointment creation. A facility with a missing tariff, a
 * misconfigured item code or an unexpected payer must still be able to register
 * patients; the visit is created, the failure is logged, and the missing charge
 * surfaces at the counter rather than at the front desk. Refusing to book the
 * patient would be a worse failure than an unpriced visit.
 */
class ConsultationChargeRaiser
{
    public function __construct(
        private readonly RaiseServiceChargeUseCase $raiseServiceCharge,
        private readonly ConsultationReviewPolicyResolver $reviewPolicyResolver,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
        private readonly RevenueTelemetryRecorderInterface $telemetry,
    ) {}

    /**
     * @param  array<string, mixed>  $appointment
     */
    public function raiseFor(array $appointment, ?int $actorUserId = null): ?ServiceChargeModel
    {
        try {
            return $this->raise($appointment, $actorUserId);
        } catch (Throwable $exception) {
            Log::warning('Unable to raise the consultation charge for an appointment.', [
                'appointment_id' => $appointment['id'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            $this->telemetry->record(
                event: RevenueTelemetryEvent::CHARGE_NOT_RAISED,
                reason: RevenueTelemetryReason::EXCEPTION,
                sourceKind: ChargeSourceKind::CONSULTATION,
                sourceWorkflowId: isset($appointment['id']) ? (string) $appointment['id'] : null,
                patientId: isset($appointment['patient_id']) ? (string) $appointment['patient_id'] : null,
                actorUserId: $actorUserId,
                detail: $exception->getMessage(),
            );

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $appointment
     */
    private function raise(array $appointment, ?int $actorUserId): ?ServiceChargeModel
    {
        if (! (bool) config('revenue.prepaid_required_for.consultation', true)) {
            return null;
        }

        $appointmentId = trim((string) ($appointment['id'] ?? ''));
        $patientId = trim((string) ($appointment['patient_id'] ?? ''));

        if ($appointmentId === '' || $patientId === '') {
            return null;
        }

        $payerClass = PayerClass::fromFinancialCoverage(
            isset($appointment['financial_coverage_type'])
                ? (string) $appointment['financial_coverage_type']
                : null,
        );

        // Anything other than self-pay has no settlement path in this phase.
        // Raising a charge nobody can clear would strand the patient at a
        // counter with no way to help them, so the visit proceeds uncharged
        // and the gate treats it as not-charged.
        if (! $payerClass->isImplemented()) {
            Log::info('Consultation left uncharged: payer class is not settled in this phase.', [
                'appointment_id' => $appointmentId,
                'payer_class' => $payerClass->value,
            ]);

            $this->telemetry->record(
                event: RevenueTelemetryEvent::CHARGE_NOT_RAISED,
                reason: RevenueTelemetryReason::PAYER_UNIMPLEMENTED,
                sourceKind: ChargeSourceKind::CONSULTATION,
                sourceWorkflowId: $appointmentId,
                patientId: $patientId,
                actorUserId: $actorUserId,
                detail: $payerClass->value,
            );

            return null;
        }

        $item = $this->resolveConsultationItem();

        if ($item === null) {
            Log::warning('Consultation left uncharged: no consultation item is configured.', [
                'appointment_id' => $appointmentId,
                'expected_code' => (string) config('revenue.consultation.default_item_code'),
            ]);

            // The signal that was missing when this gate sat dead in every
            // environment: config named an item the catalogue did not hold, and
            // the only evidence was this log line.
            $this->telemetry->record(
                event: RevenueTelemetryEvent::CHARGE_NOT_RAISED,
                reason: RevenueTelemetryReason::NO_ITEM,
                sourceKind: ChargeSourceKind::CONSULTATION,
                sourceWorkflowId: $appointmentId,
                patientId: $patientId,
                actorUserId: $actorUserId,
                detail: (string) config('revenue.consultation.default_item_code'),
            );

            return null;
        }

        [$discountPercent, $discountReason] = $this->resolveReviewDiscount($appointment);

        return $this->raiseServiceCharge->execute(
            patientId: $patientId,
            sourceKind: ChargeSourceKind::CONSULTATION,
            sourceId: $appointmentId,
            chargeableItemId: (string) $item->id,
            description: (string) $item->name,
            appointmentId: $appointmentId,
            payerClass: $payerClass,
            discountPercent: $discountPercent,
            discountReason: $discountReason,
            unit: (string) ($item->default_unit ?? 'visit'),
            actorUserId: $actorUserId,
        );
    }

    /**
     * A review visit within the follow-up window is charged at a reduced rate,
     * or nothing at all, per config/consultation_policy.php — the policy the
     * appointment module already applies when it classifies the visit.
     *
     * @param  array<string, mixed>  $appointment
     * @return array{0: float|null, 1: string|null}
     */
    private function resolveReviewDiscount(array $appointment): array
    {
        $consultationType = strtolower(trim((string) ($appointment['consultation_type'] ?? '')));

        if ($consultationType !== 'review') {
            return [null, null];
        }

        $policy = $this->reviewPolicyResolver->resolve($this->scopeContext->facilityId());

        if ((bool) ($policy['review_fee_is_free'] ?? false)) {
            return [100.0, 'Review visit — no consultation fee'];
        }

        $feePercentage = (float) ($policy['review_fee_percentage'] ?? 100.0);
        $discountPercent = max(0.0, 100.0 - $feePercentage);

        return $discountPercent > 0.0
            ? [$discountPercent, sprintf('Review visit — charged at %s%% of the standard fee', rtrim(rtrim(number_format($feePercentage, 2, '.', ''), '0'), '.'))]
            : [null, null];
    }

    private function resolveConsultationItem(): ?ChargeableItemModel
    {
        $code = strtoupper(trim((string) config('revenue.consultation.default_item_code')));

        if ($code === '') {
            return null;
        }

        $facilityId = $this->scopeContext->facilityId();

        return ChargeableItemModel::query()
            ->whereRaw('UPPER(code) = ?', [$code])
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('facility_id')->orWhere('facility_id', $facilityId))
            // Prefer the facility's own item over a globally scoped one, the
            // same precedence the price book uses.
            ->orderByRaw('CASE WHEN facility_id IS NULL THEN 1 ELSE 0 END')
            ->first();
    }
}

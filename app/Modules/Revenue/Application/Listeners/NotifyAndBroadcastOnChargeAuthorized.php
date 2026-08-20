<?php

namespace App\Modules\Revenue\Application\Listeners;

use App\Modules\ClinicalProcedure\Domain\Events\ClinicalProcedureQueueUpdated;
use App\Modules\ClinicalProcedure\Infrastructure\Models\ClinicalProcedureOrderModel;
use App\Modules\Laboratory\Domain\Events\LaboratoryQueueUpdated;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Notifications\Application\Listeners\DispatchInAppNotification;
use App\Modules\PatientFlow\Domain\Events\PatientFlowBoardUpdated;
use App\Modules\Pharmacy\Domain\Events\PharmacyQueueUpdated;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Radiology\Domain\Events\RadiologyQueueUpdated;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\Revenue\Domain\Events\ServiceChargeAuthorized;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When a service charge is authorized (via payment, waiver, or emergency override):
 * 1. Broadcasts queue update to the corresponding departmental workstation.
 * 2. Broadcasts board update to the facility's patient-flow channel.
 * 3. Sends an in-app notification to the ordering clinician.
 */
class NotifyAndBroadcastOnChargeAuthorized
{
    public function __construct(
        private readonly DispatchInAppNotification $dispatchInAppNotification,
    ) {}

    public function handle(ServiceChargeAuthorized $event): void
    {
        try {
            $charge = ServiceChargeModel::query()->find($event->serviceChargeId);
            $facilityId = $charge?->facility_id;

            // Broadcast to the facility patient-flow board so clinician and reception views refresh
            if ($facilityId !== null) {
                event(new PatientFlowBoardUpdated($facilityId));
            }

            switch ($event->sourceKind) {
                case ChargeSourceKind::LABORATORY_ORDER:
                    $this->handleLaboratoryOrder($event, $facilityId);
                    break;

                case ChargeSourceKind::RADIOLOGY_ORDER:
                    $this->handleRadiologyOrder($event, $facilityId);
                    break;

                case ChargeSourceKind::PHARMACY_ORDER:
                    $this->handlePharmacyOrder($event, $facilityId);
                    break;

                case ChargeSourceKind::CLINICAL_PROCEDURE_ORDER:
                    $this->handleClinicalProcedureOrder($event, $facilityId);
                    break;

                default:
                    break;
            }
        } catch (Throwable $e) {
            Log::error('Failed to process post-authorization broadcasting and notifications', [
                'serviceChargeId' => $event->serviceChargeId,
                'sourceKind' => $event->sourceKind->value,
                'sourceId' => $event->sourceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleLaboratoryOrder(ServiceChargeAuthorized $event, ?string $facilityId): void
    {
        if ($facilityId !== null) {
            event(new LaboratoryQueueUpdated($facilityId));
        }

        if ($event->sourceId === null) {
            return;
        }

        $order = LaboratoryOrderModel::query()->find($event->sourceId);
        if ($order === null || $order->ordered_by_user_id === null) {
            return;
        }

        $itemName = $order->test_name ?? $order->test_code ?? 'Lab Test';
        $orderNumber = $order->order_number ?? $order->id;

        $this->dispatchInAppNotification->handle(
            userId: (int) $order->ordered_by_user_id,
            category: 'laboratory',
            priority: 'normal',
            title: 'Payment verified · Lab order authorized',
            body: sprintf('Payment has been verified for %s (Order #%s). The test is now authorized for processing.', $itemName, $orderNumber),
            actionUrl: sprintf('/laboratory-orders?focusOrderId=%s', $order->id),
            actionLabel: 'View order',
            contextType: 'laboratory_order',
            contextId: (string) $order->id,
        );
    }

    private function handleRadiologyOrder(ServiceChargeAuthorized $event, ?string $facilityId): void
    {
        if ($facilityId !== null) {
            event(new RadiologyQueueUpdated($facilityId));
        }

        if ($event->sourceId === null) {
            return;
        }

        $order = RadiologyOrderModel::query()->find($event->sourceId);
        if ($order === null || $order->ordered_by_user_id === null) {
            return;
        }

        $itemName = $order->procedure_name ?? $order->procedure_code ?? 'Imaging Study';
        $orderNumber = $order->order_number ?? $order->id;

        $this->dispatchInAppNotification->handle(
            userId: (int) $order->ordered_by_user_id,
            category: 'clinical',
            priority: 'normal',
            title: 'Payment verified · Imaging order authorized',
            body: sprintf('Payment has been verified for %s (Order #%s). The study is now authorized for execution.', $itemName, $orderNumber),
            actionUrl: sprintf('/radiology-orders?focusOrderId=%s', $order->id),
            actionLabel: 'View order',
            contextType: 'radiology_order',
            contextId: (string) $order->id,
        );
    }

    private function handlePharmacyOrder(ServiceChargeAuthorized $event, ?string $facilityId): void
    {
        if ($facilityId !== null) {
            event(new PharmacyQueueUpdated($facilityId));
        }

        if ($event->sourceId === null) {
            return;
        }

        $order = PharmacyOrderModel::query()->find($event->sourceId);
        if ($order === null || $order->ordered_by_user_id === null) {
            return;
        }

        $itemName = $order->drug_name ?? $order->medication_name ?? 'Prescription';
        $orderNumber = $order->order_number ?? $order->id;

        $this->dispatchInAppNotification->handle(
            userId: (int) $order->ordered_by_user_id,
            category: 'pharmacy',
            priority: 'normal',
            title: 'Payment verified · Prescription authorized',
            body: sprintf('Payment has been verified for %s (Order #%s). The medication is now authorized for dispensing.', $itemName, $orderNumber),
            actionUrl: sprintf('/pharmacy-orders?focusOrderId=%s', $order->id),
            actionLabel: 'View order',
            contextType: 'pharmacy_order',
            contextId: (string) $order->id,
        );
    }

    private function handleClinicalProcedureOrder(ServiceChargeAuthorized $event, ?string $facilityId): void
    {
        if ($facilityId !== null) {
            event(new ClinicalProcedureQueueUpdated($facilityId));
        }

        if ($event->sourceId === null) {
            return;
        }

        $order = ClinicalProcedureOrderModel::query()->find($event->sourceId);
        if ($order === null || $order->ordered_by_user_id === null) {
            return;
        }

        $itemName = $order->procedure_description ?? $order->procedure_code ?? 'Clinical Procedure';
        $orderNumber = $order->order_number ?? $order->id;

        $this->dispatchInAppNotification->handle(
            userId: (int) $order->ordered_by_user_id,
            category: 'clinical',
            priority: 'normal',
            title: 'Payment verified · Procedure authorized',
            body: sprintf('Payment has been verified for %s (Order #%s). The procedure is now authorized to be performed.', $itemName, $orderNumber),
            actionUrl: sprintf('/clinical-procedures?focusOrderId=%s', $order->id),
            actionLabel: 'View order',
            contextType: 'clinical_procedure_order',
            contextId: (string) $order->id,
        );
    }
}

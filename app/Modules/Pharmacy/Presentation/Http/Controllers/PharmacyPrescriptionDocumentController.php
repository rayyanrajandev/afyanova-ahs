<?php

namespace App\Modules\Pharmacy\Presentation\Http\Controllers;

use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Support\Documents\DocumentContextLookup;
use App\Support\Branding\SystemBrandingManager;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PharmacyPrescriptionDocumentController
{
    public function __construct(
        private readonly PharmacyOrderRepositoryInterface $pharmacyOrderRepository,
        private readonly DocumentContextLookup $documentContextLookup,
        private readonly SystemBrandingManager $brandingManager,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function show(string $id): Response
    {
        $order = $this->resolveOrder($id);
        $context = $this->buildViewContext($order);

        return Inertia::render('pharmacy-orders/PrescriptionPrintView', [
            ...$context,
            'documentBranding' => $this->brandingManager->documentBranding(),
            'facilityName' => $this->facilityName(),
            'generatedAt' => now()->toDateTimeString(),
            'batch' => false,
        ]);
    }

    public function showBatch(): Response
    {
        $ids = request()->query('ids', '');
        $idList = array_filter(array_map('trim', explode(',', $ids)));

        abort_if(empty($idList), 422, 'No order IDs provided.');

        $orders = [];
        foreach ($idList as $id) {
            $order = $this->pharmacyOrderRepository->findById($id);
            if ($order === null) continue;
            $orders[] = $this->buildViewContext($order);
        }

        abort_if(empty($orders), 404, 'No valid pharmacy orders found.');

        return Inertia::render('pharmacy-orders/PrescriptionPrintView', [
            'orders' => $orders,
            'documentBranding' => $this->brandingManager->documentBranding(),
            'facilityName' => $this->facilityName(),
            'generatedAt' => now()->toDateTimeString(),
            'batch' => true,
        ]);
    }

    private function resolveOrder(string $id): array
    {
        $order = $this->pharmacyOrderRepository->findById($id);
        abort_if($order === null, 404, 'Pharmacy order not found.');
        return $order;
    }

    private function buildViewContext(array $order): array
    {
        $patient = null;
        $patientId = $order['patient_id'] ?? null;
        if ($patientId !== null) {
            $patient = $this->documentContextLookup->patientSummary($patientId);
        }

        $orderedBy = null;
        $orderedByUserId = $order['ordered_by_user_id'] ?? null;
        if ($orderedByUserId !== null) {
            $orderedBy = $this->documentContextLookup->userSummary((int) $orderedByUserId);
        }

        return [
            'order' => $this->camelCaseKeys($order),
            'patient' => $patient,
            'orderedBy' => $orderedBy,
        ];
    }

    private function facilityName(): ?string
    {
        $facility = $this->scopeContext->facility();
        return $facility['name'] ?? null;
    }

    private function camelCaseKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[Str::camel($key)] = $value;
        }
        return $result;
    }
}

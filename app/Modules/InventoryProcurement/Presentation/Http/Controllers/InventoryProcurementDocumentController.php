<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryProcurementRequestRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseRepositoryInterface;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventoryProcurementRequestResponseTransformer;
use App\Support\Branding\SystemBrandingManager;
use App\Support\Documents\BrandedPdfDocumentManager;
use App\Support\Documents\DocumentContextLookup;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class InventoryProcurementDocumentController extends Controller
{
    public function showGoodsReceivedNote(
        string $id,
        InventoryProcurementRequestRepositoryInterface $repository,
        InventoryWarehouseRepositoryInterface $warehouseRepository,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): Response {
        return Inertia::render('inventory-procurement/ProcurementGrnPrint', $this->buildPayload(
            id: $id,
            repository: $repository,
            warehouseRepository: $warehouseRepository,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        ));
    }

    public function downloadGoodsReceivedNotePdf(
        string $id,
        InventoryProcurementRequestRepositoryInterface $repository,
        InventoryWarehouseRepositoryInterface $warehouseRepository,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
        BrandedPdfDocumentManager $pdfDocumentManager,
    ): HttpResponse {
        $payload = $this->buildPayload(
            id: $id,
            repository: $repository,
            warehouseRepository: $warehouseRepository,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        );
        $payload['documentBranding'] = $brandingManager->documentPdfBranding();

        $request = is_array($payload['request'] ?? null) ? $payload['request'] : [];
        $safeIdentifier = $pdfDocumentManager->sanitizeIdentifier(
            (string) ($request['requestNumber'] ?? $request['purchaseOrderNumber'] ?? $id),
            'procurement_grn',
        );

        return $pdfDocumentManager->downloadView(
            view: 'documents.inventory-procurement-grn',
            data: $payload,
            baseName: 'goods_received_note_'.$safeIdentifier,
            extraHeaders: [
                'X-Document-Source' => 'inventory-procurement-grn',
                'X-Document-Source-Id' => (string) ($request['id'] ?? $id),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $id,
        InventoryProcurementRequestRepositoryInterface $repository,
        InventoryWarehouseRepositoryInterface $warehouseRepository,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): array {
        $procurementRequest = $repository->findById($id);
        abort_if($procurementRequest === null, 404, 'Procurement request not found.');

        $status = (string) ($procurementRequest['status'] ?? '');
        abort_unless($status === 'received', 404, 'Goods received note is available after receipt is confirmed.');

        $transformed = InventoryProcurementRequestResponseTransformer::transform($procurementRequest);
        $warehouseId = $procurementRequest['receiving_warehouse_id'] ?? null;
        $receivingWarehouseName = null;

        if (is_string($warehouseId) && $warehouseId !== '') {
            $warehouse = $warehouseRepository->findById($warehouseId);
            $receivingWarehouseName = is_array($warehouse)
                ? trim((string) ($warehouse['warehouse_name'] ?? ''))
                : null;
            if ($receivingWarehouseName === '') {
                $receivingWarehouseName = null;
            }
        }

        return [
            'request' => $transformed,
            'receivingWarehouseName' => $receivingWarehouseName,
            'requestedBy' => $documentContextLookup->userSummary($procurementRequest['requested_by_user_id'] ?? null),
            'approvedBy' => $documentContextLookup->userSummary($procurementRequest['approved_by_user_id'] ?? null),
            'receivedBy' => $documentContextLookup->userSummary($procurementRequest['received_by_user_id'] ?? null),
            'documentBranding' => $brandingManager->documentBranding(),
            'generatedAt' => now()->toISOString(),
        ];
    }
}

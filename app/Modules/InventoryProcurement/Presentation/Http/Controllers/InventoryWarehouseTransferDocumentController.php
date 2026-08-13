<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseTransferRepositoryInterface;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventoryWarehouseTransferResponseTransformer;
use App\Support\Branding\SystemBrandingManager;
use App\Support\Documents\BrandedPdfDocumentManager;
use App\Support\Documents\DocumentContextLookup;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class InventoryWarehouseTransferDocumentController extends Controller
{
    public function showPickSlip(
        string $id,
        InventoryWarehouseTransferRepositoryInterface $repository,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): Response {
        return Inertia::render('inventory-procurement/TransferPrint', $this->buildPayload(
            id: $id,
            documentType: 'pick_slip',
            repository: $repository,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        ));
    }

    public function downloadPickSlipPdf(
        string $id,
        InventoryWarehouseTransferRepositoryInterface $repository,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
        BrandedPdfDocumentManager $pdfDocumentManager,
    ): HttpResponse {
        $payload = $this->buildPayload(
            id: $id,
            documentType: 'pick_slip',
            repository: $repository,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        );
        $payload['documentBranding'] = $brandingManager->documentPdfBranding();

        $safeIdentifier = $pdfDocumentManager->sanitizeIdentifier(
            (string) ($payload['transfer']['transfer_number'] ?? $id),
            'warehouse_transfer_pick_slip',
        );

        return $pdfDocumentManager->downloadView(
            view: 'documents.inventory-warehouse-transfer',
            data: $payload,
            baseName: 'warehouse_transfer_pick_slip_'.$safeIdentifier,
            extraHeaders: [
                'X-Document-Source' => 'inventory-warehouse-transfer-pick-slip',
                'X-Document-Source-Id' => (string) ($payload['transfer']['id'] ?? $id),
            ],
            orientation: 'landscape',
        );
    }

    public function showDispatchNote(
        string $id,
        InventoryWarehouseTransferRepositoryInterface $repository,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): Response {
        return Inertia::render('inventory-procurement/TransferPrint', $this->buildPayload(
            id: $id,
            documentType: 'dispatch_note',
            repository: $repository,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        ));
    }

    public function downloadDispatchNotePdf(
        string $id,
        InventoryWarehouseTransferRepositoryInterface $repository,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
        BrandedPdfDocumentManager $pdfDocumentManager,
    ): HttpResponse {
        $payload = $this->buildPayload(
            id: $id,
            documentType: 'dispatch_note',
            repository: $repository,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        );
        $payload['documentBranding'] = $brandingManager->documentPdfBranding();

        $safeIdentifier = $pdfDocumentManager->sanitizeIdentifier(
            (string) ($payload['transfer']['dispatch_note_number'] ?? $payload['transfer']['transfer_number'] ?? $id),
            'warehouse_transfer_dispatch_note',
        );

        return $pdfDocumentManager->downloadView(
            view: 'documents.inventory-warehouse-transfer',
            data: $payload,
            baseName: 'warehouse_transfer_dispatch_note_'.$safeIdentifier,
            extraHeaders: [
                'X-Document-Source' => 'inventory-warehouse-transfer-dispatch-note',
                'X-Document-Source-Id' => (string) ($payload['transfer']['id'] ?? $id),
            ],
            orientation: 'landscape',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $id,
        string $documentType,
        InventoryWarehouseTransferRepositoryInterface $repository,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): array {
        $transfer = $repository->findById($id);
        abort_if($transfer === null, 404, 'Warehouse transfer not found.');

        $transformedTransfer = InventoryWarehouseTransferResponseTransformer::transform($transfer);
        $this->ensureDocumentIsAvailable($transformedTransfer, $documentType);

        return [
            'documentType' => $documentType,
            'transfer' => $transformedTransfer,
            'requestedBy' => $documentContextLookup->userSummary($transfer['requested_by_user_id'] ?? null),
            'approvedBy' => $documentContextLookup->userSummary($transfer['approved_by_user_id'] ?? null),
            'packedBy' => $documentContextLookup->userSummary($transfer['packed_by_user_id'] ?? null),
            'dispatchedBy' => $documentContextLookup->userSummary($transfer['dispatched_by_user_id'] ?? null),
            'receivedBy' => $documentContextLookup->userSummary($transfer['received_by_user_id'] ?? null),
            'documentBranding' => $brandingManager->documentBranding(),
            'generatedAt' => now()->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $transfer
     */
    private function ensureDocumentIsAvailable(array $transfer, string $documentType): void
    {
        $status = (string) ($transfer['status'] ?? '');

        if ($documentType === 'pick_slip') {
            abort_unless(in_array($status, ['approved', 'packed', 'in_transit', 'received'], true), 404, 'Pick slip is available after approval.');

            return;
        }

        abort_unless(in_array($status, ['packed', 'in_transit', 'received'], true), 404, 'Dispatch note is available after packing.');
    }
}

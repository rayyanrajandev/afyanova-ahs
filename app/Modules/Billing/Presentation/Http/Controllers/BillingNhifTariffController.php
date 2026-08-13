<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Modules\Billing\Infrastructure\Integrations\NHIF\NhifTariffSyncService;
use App\Modules\Billing\Infrastructure\Models\BillingNhifTariffImportModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BillingNhifTariffController extends Controller
{
    public function __construct(
        private readonly NhifTariffSyncService $tariffSyncService,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => 'nullable|string|max:50',
            'effective_date' => 'nullable|date',
        ]);

        try {
            $preview = $this->tariffSyncService->previewTariffSchedule(
                version: $validated['version'] ?? null,
                effectiveDate: $validated['effective_date'] ?? null,
            );

            return response()->json([
                'success' => true,
                'data' => $preview,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => 'nullable|string|max:50',
            'effective_date' => 'nullable|date',
        ]);

        try {
            $import = $this->tariffSyncService->importTariffSchedule(
                tenantId: $this->scopeContext->tenantId(),
                facilityId: $this->scopeContext->facilityId(),
                version: $validated['version'] ?? null,
                effectiveDate: $validated['effective_date'] ?? null,
                importedByUserId: $request->user()?->id,
            );

            return response()->json([
                'success' => true,
                'message' => "Imported {$import->items_imported} new, updated {$import->items_updated}, skipped {$import->items_skipped}",
                'data' => $import,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    public function importHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = min((int) ($validated['per_page'] ?? 20), 100);

        $imports = BillingNhifTariffImportModel::query()
            ->where('tenant_id', $this->scopeContext->tenantId())
            ->where('facility_id', $this->scopeContext->facilityId())
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json(['data' => $imports]);
    }

    public function catalogItems(Request $request): JsonResponse
    {
        $items = ChargeableItemModel::query()
            ->where('tenant_id', $this->scopeContext->tenantId())
            ->where('facility_id', $this->scopeContext->facilityId())
            ->where('catalog_type', 'nhif_tariff')
            ->orderBy('name')
            ->paginate(min((int) ($request->input('per_page', 50)), 100));

        return response()->json(['data' => $items]);
    }
}

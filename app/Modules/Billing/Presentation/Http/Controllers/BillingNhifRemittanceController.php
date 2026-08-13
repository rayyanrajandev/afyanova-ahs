<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Jobs\ProcessNhifRemittanceFileJob;
use App\Modules\Billing\Infrastructure\Models\BillingNhifRemittanceModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BillingNhifRemittanceController extends Controller
{
    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    /**
     * Parsing + reconciling a remittance file (potentially hundreds of
     * claims, one DB write per claim inside a single transaction) used to
     * run synchronously here. It now only stores the file and creates a
     * 'pending' row, then hands the actual work to
     * ProcessNhifRemittanceFileJob — history()/show() already expose
     * status, so no new polling endpoint is needed.
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:csv,txt,json|max:10240',
            'format' => 'nullable|string|in:csv,json',
        ]);

        $file = $request->file('file');
        $format = $validated['format'] ?? $file->getClientOriginalExtension();
        $normalizedFormat = $format === 'json' ? 'json' : 'csv';

        $storedPath = $file->store('nhif-remittance-uploads', 'local');

        $remittance = BillingNhifRemittanceModel::query()->create([
            'tenant_id' => $this->scopeContext->tenantId(),
            'facility_id' => $this->scopeContext->facilityId(),
            'remittance_reference' => sprintf('PENDING-%s', (string) Str::uuid()),
            'remittance_date' => now()->toDateString(),
            'source' => 'upload',
            'original_filename' => $file->getClientOriginalName(),
            'status' => 'pending',
            'uploaded_by_user_id' => $request->user()?->id,
        ]);

        ProcessNhifRemittanceFileJob::dispatch(
            (string) $remittance->id,
            $storedPath,
            $normalizedFormat,
        );

        return response()->json([
            'success' => true,
            'message' => 'Remittance file received and queued for processing.',
            'data' => [
                'id' => $remittance->id,
                'status' => $remittance->status,
            ],
        ], 202);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|string|in:pending,completed,partial,failed',
        ]);

        $query = BillingNhifRemittanceModel::query()
            ->where('tenant_id', $this->scopeContext->tenantId())
            ->where('facility_id', $this->scopeContext->facilityId());

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $perPage = min((int) ($validated['per_page'] ?? 20), 100);

        return response()->json([
            'data' => $query->orderByDesc('created_at')->paginate($perPage),
        ]);
    }

    public function show(Request $request, string $remittanceId): JsonResponse
    {
        $remittance = BillingNhifRemittanceModel::query()
            ->where('id', $remittanceId)
            ->where('tenant_id', $this->scopeContext->tenantId())
            ->where('facility_id', $this->scopeContext->facilityId())
            ->with('items')
            ->first();

        if (!$remittance) {
            return response()->json(['message' => 'Remittance not found'], 404);
        }

        return response()->json(['data' => $remittance]);
    }
}

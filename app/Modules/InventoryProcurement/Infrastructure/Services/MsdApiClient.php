<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Services;

use App\Modules\InventoryProcurement\Domain\Services\MsdApiClientInterface;
use Illuminate\Support\Facades\Log;

/**
 * MSD API client for development. Returns successful stub responses.
 * Replace internals with real HTTP client when MSD provides API endpoint + credentials.
 */
class MsdApiClient implements MsdApiClientInterface
{
    public function submitOrder(array $orderPayload): array
    {
        Log::info('[MSD Stub] Order submitted', [
            'facility_msd_code' => $orderPayload['facility_msd_code'] ?? null,
            'line_count' => count($orderPayload['order_lines'] ?? []),
        ]);

        return [
            'success' => true,
            'submission_reference' => 'MSD-STUB-' . strtoupper(substr(md5(uniqid()), 0, 8)),
            'errors' => [],
        ];
    }

    public function queryOrderStatus(string $submissionReference): array
    {
        Log::info('[MSD Stub] Status queried', ['reference' => $submissionReference]);

        return [
            'status' => 'confirmed',
            'dispatched_at' => null,
            'delivery_note_number' => null,
            'errors' => [],
        ];
    }

    public function healthCheck(): array
    {
        return [
            'connected' => true,
            'message' => 'MSD API stub — replace with real implementation when API credentials are available.',
        ];
    }
}

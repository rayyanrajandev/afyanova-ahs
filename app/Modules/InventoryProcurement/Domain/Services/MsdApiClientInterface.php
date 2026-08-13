<?php

namespace App\Modules\InventoryProcurement\Domain\Services;

/**
 * API contract for Medical Stores Department (MSD) electronic ordering.
 *
 * Implementors connect to MSD's e-ordering API (or use a stub for development).
 * The real implementation will be injected when MSD provides API credentials.
 */
interface MsdApiClientInterface
{
    /**
     * Submit an order to MSD e-ordering system.
     *
     * @param  array{
     *     facility_msd_code: string,
     *     order_lines: array<array{msd_code: string, item_name: string, quantity: float, unit: string}>,
     *     order_date: string,
     *     notes: ?string,
     * }  $orderPayload
     * @return array{success: bool, submission_reference: ?string, errors: array<string>}
     */
    public function submitOrder(array $orderPayload): array;

    /**
     * Query the status of a previously submitted order.
     *
     * @return array{status: string, dispatched_at: ?string, delivery_note_number: ?string, errors: array<string>}
     */
    public function queryOrderStatus(string $submissionReference): array;

    /**
     * Check connectivity / authentication with MSD API.
     *
     * @return array{connected: bool, message: string}
     */
    public function healthCheck(): array;
}

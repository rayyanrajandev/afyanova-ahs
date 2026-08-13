<?php

namespace App\Modules\InventoryProcurement\Application\Services;

use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use Illuminate\Support\Facades\DB;

/**
 * Hybrid Procurement Routing Service
 *
 * Routes procurement requests through the appropriate approval workflow:
 * - AUTO: Direct to supplier (routine catalog items, within quantity/budget limits)
 * - NEEDS_REVIEW: Central store approval required (off-catalog, high-value, bulk)
 */
class ProcurementApprovalRoutingService
{
    /**
     * Determine if department can direct-order this item (auto route) or needs central store approval
     */
    public function determineApprovalRoute(
        string $departmentId,
        string $itemId,
        float $requestedQuantity,
        ?float $unitCostEstimate = null,
    ): array {
        $department = DepartmentModel::find($departmentId);
        if (!$department) {
            return [
                'route' => 'needs_review',
                'reason' => 'Department not found',
                'is_catalog_item' => false,
            ];
        }

        // Check if item is in the department's approved catalog
        $isCatalogItem = $this->isItemInDepartmentCatalog($departmentId, $itemId);

        if (!$isCatalogItem) {
            return [
                'route' => 'needs_review',
                'reason' => 'Item not in department approved catalog',
                'is_catalog_item' => false,
            ];
        }

        // Check quantity thresholds
        $quantityThreshold = $this->getDepartmentQuantityThreshold($departmentId);
        if ($requestedQuantity > $quantityThreshold) {
            return [
                'route' => 'needs_review',
                'reason' => "Quantity ({$requestedQuantity}) exceeds department threshold ({$quantityThreshold})",
                'is_catalog_item' => true,
            ];
        }

        // Check budget constraints
        if ($unitCostEstimate !== null) {
            $monthlyBudgetRemaining = $this->getMonthlyBudgetRemaining($departmentId);
            $totalCostEstimate = $requestedQuantity * $unitCostEstimate;

            if ($totalCostEstimate > $monthlyBudgetRemaining) {
                return [
                    'route' => 'needs_review',
                    'reason' => "Cost estimate ({$totalCostEstimate}) exceeds monthly budget remaining ({$monthlyBudgetRemaining})",
                    'is_catalog_item' => true,
                ];
            }
        }

        // Item is catalog item, within quantity and budget limits → auto route
        return [
            'route' => 'auto',
            'reason' => 'Routine catalog item within department limits',
            'is_catalog_item' => true,
        ];
    }

    /**
     * Check if an active procurement request already exists for this item in this department
     * Returns the existing request if found, null otherwise
     */
    public function findActiveDuplicateRequest(string $departmentId, string $itemId): ?array
    {
        // Active statuses: pending_approval, approved, ordered (not received or rejected)
        $activeStatuses = ['pending_approval', 'approved', 'ordered'];

        return DB::table('inventory_procurement_requests')
            ->where('requesting_department_id', $departmentId)
            ->where('item_id', $itemId)
            ->whereIn('status', $activeStatuses)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Generate a hash for duplicate detection
     */
    public function generateDuplicateCheckHash(string $departmentId, string $itemId, string $status = 'pending_approval'): string
    {
        return md5("{$departmentId}:{$itemId}:{$status}");
    }

    /**
     * Check if item is in the department's approved catalog
     * For now, all items are approved; this can be extended with a separate approval_catalog table
     */
    private function isItemInDepartmentCatalog(string $departmentId, string $itemId): bool
    {
        // FUTURE: Check against department-specific approved item list
        // For MVP: All items are approved if they pass DepartmentRequisitionScopeResolver
        return true;
    }

    /**
     * Get department's quantity threshold for auto-routing (e.g., max qty per single request)
     * Can be customized per department or use facility default
     */
    private function getDepartmentQuantityThreshold(string $departmentId): float
    {
        // FUTURE: Store per-department thresholds in config or database
        // For MVP: Use facility-wide default
        return 100; // Max 100 units per request for auto-routing
    }

    /**
     * Get remaining monthly budget for department
     * Prevents departments from over-spending
     */
    private function getMonthlyBudgetRemaining(string $departmentId): float
    {
        // FUTURE: Track monthly departmental budgets and utilization
        // For MVP: Unlimited budget (always has remaining)
        return PHP_INT_MAX;
    }
}

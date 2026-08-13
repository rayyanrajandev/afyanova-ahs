<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryApprovalRuleModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_approval_rules';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'department_id',
        'role_id',
        'approval_type',
        'approval_permissions',
        'max_requisition_amount',
        'max_items_count',
        'allowed_categories',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approval_permissions' => 'json',
            'allowed_categories' => 'json',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Check if rule is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get approval permissions
     *
     * @return array<string, mixed>
     */
    public function getApprovalPermissions(): array
    {
        return $this->approval_permissions ?? [];
    }

    /**
     * Check if can approve own department
     */
    public function canApproveOwnDepartment(): bool
    {
        $perms = $this->getApprovalPermissions();
        return $perms['can_approve_own_dept'] ?? false;
    }

    /**
     * Check if can approve other departments
     */
    public function canApproveOtherDepartments(): bool
    {
        $perms = $this->getApprovalPermissions();
        return $perms['can_approve_other_dept'] ?? false;
    }

    /**
     * Check if requisition amount is within authority
     */
    public function isAmountWithinAuthority(?int $amount): bool
    {
        if ($this->max_requisition_amount === null) {
            return true; // No limit
        }

        return ($amount ?? 0) <= $this->max_requisition_amount;
    }

    /**
     * Check if item count is within authority
     */
    public function isItemCountWithinAuthority(?int $itemCount): bool
    {
        if ($this->max_items_count === null) {
            return true; // No limit
        }

        return ($itemCount ?? 0) <= $this->max_items_count;
    }

    /**
     * Check if category is allowed
     */
    public function isCategoryAllowed(?string $category): bool
    {
        $categories = $this->allowed_categories ?? [];
        
        if (empty($categories)) {
            return true; // No restrictions
        }

        return in_array($category, $categories);
    }

    /**
     * Get allowed categories
     *
     * @return array<int, string>
     */
    public function getAllowedCategories(): array
    {
        return $this->allowed_categories ?? [];
    }

    /**
     * Check if approver has authority to approve this requisition
     */
    public function canApproveRequisition(
        InventoryDepartmentRequisitionModel $requisition,
        ?int $totalAmount = null
    ): bool
    {
        // Check amount authority
        if (!$this->isAmountWithinAuthority($totalAmount)) {
            return false;
        }

        // Check item count authority
        $itemCount = $requisition->lines()->count();
        if (!$this->isItemCountWithinAuthority($itemCount)) {
            return false;
        }

        // Check category restrictions
        $categories = $requisition->lines()
            ->join('inventory_items', 'inventory_department_requisition_lines.item_id', '=', 'inventory_items.id')
            ->pluck('inventory_items.category')
            ->unique();

        foreach ($categories as $category) {
            if (!$this->isCategoryAllowed($category)) {
                return false;
            }
        }

        return true;
    }
}

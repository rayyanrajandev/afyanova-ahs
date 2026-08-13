<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryApprovalWorkflowVersionChangeModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_approval_workflow_version_changes';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'workflow_id',
        'version_number',
        'change_type',
        'before_state',
        'after_state',
        'changed_by_user_type',
        'changed_by_user_id',
        'change_reason',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'before_state' => 'json',
            'after_state' => 'json',
            'changed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(
            InventoryApprovalWorkflowModel::class,
            'workflow_id',
            'id'
        );
    }
}

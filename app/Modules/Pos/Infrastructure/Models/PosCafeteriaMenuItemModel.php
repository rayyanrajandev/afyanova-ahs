<?php

namespace App\Modules\Pos\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PosCafeteriaMenuItemModel extends Model
{
    use HasUuids;

    protected $table = 'pos_cafeteria_menu_items';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'item_code',
        'item_name',
        'category',
        'unit_label',
        'unit_price',
        'tax_rate_percent',
        'status',
        'status_reason',
        'sort_order',
        'description',
        'metadata',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'tax_rate_percent' => 'decimal:2',
            'sort_order' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

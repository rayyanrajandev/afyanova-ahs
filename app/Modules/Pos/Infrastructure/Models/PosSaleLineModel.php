<?php

namespace App\Modules\Pos\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSaleLineModel extends Model
{
    use HasUuids;

    protected $table = 'pos_sale_lines';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'pos_sale_id',
        'line_number',
        'item_type',
        'item_reference',
        'item_code',
        'item_name',
        'quantity',
        'unit_price',
        'line_subtotal_amount',
        'discount_amount',
        'tax_amount',
        'line_total_amount',
        'notes',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'line_subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total_amount' => 'decimal:2',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSaleModel::class, 'pos_sale_id');
    }
}

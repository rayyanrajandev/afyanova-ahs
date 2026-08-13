<?php

namespace App\Modules\Pos\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSalePaymentModel extends Model
{
    use HasUuids;

    protected $table = 'pos_sale_payments';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'pos_sale_id',
        'payment_method',
        'amount_received',
        'amount_applied',
        'change_given',
        'payment_reference',
        'paid_at',
        'collected_by_user_id',
        'note',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_received' => 'decimal:2',
            'amount_applied' => 'decimal:2',
            'change_given' => 'decimal:2',
            'paid_at' => 'datetime',
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

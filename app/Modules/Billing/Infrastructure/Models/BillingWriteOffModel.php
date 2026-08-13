<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingWriteOffModel extends Model
{
    use HasUuids;

    protected $table = 'billing_write_offs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'billing_invoice_id',
        'patient_id',
        'amount',
        'reason',
        'status',
        'approved_by_user_id',
        'approved_at',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

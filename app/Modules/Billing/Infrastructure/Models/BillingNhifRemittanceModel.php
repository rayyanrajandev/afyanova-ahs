<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingNhifRemittanceModel extends Model
{
    use HasUuids;

    protected $table = 'billing_nhif_remittances';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'remittance_reference',
        'remittance_date',
        'payer_name',
        'total_amount',
        'total_claims',
        'matched_claims',
        'matched_amount',
        'unmatched_amount',
        'source',
        'original_filename',
        'raw_data',
        'status',
        'error_message',
        'processed_at',
        'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'remittance_date' => 'date',
            'total_amount' => 'decimal:2',
            'matched_amount' => 'decimal:2',
            'unmatched_amount' => 'decimal:2',
            'raw_data' => 'array',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingNhifRemittanceItemModel::class, 'billing_nhif_remittance_id');
    }
}

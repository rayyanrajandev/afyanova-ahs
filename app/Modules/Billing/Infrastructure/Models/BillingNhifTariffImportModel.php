<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingNhifTariffImportModel extends Model
{
    use HasUuids;

    protected $table = 'billing_nhif_tariff_imports';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'tariff_version',
        'effective_date',
        'items_imported',
        'items_updated',
        'items_skipped',
        'import_log',
        'status',
        'imported_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'import_log' => 'array',
            'items_imported' => 'integer',
            'items_updated' => 'integer',
            'items_skipped' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'imported_by_user_id');
    }
}

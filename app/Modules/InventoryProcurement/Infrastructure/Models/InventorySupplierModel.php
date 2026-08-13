<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventorySupplierModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_suppliers';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'supplier_code',
        'supplier_name',
        'tin_number',
        'bank_account_number',
        'lipa_number',
        'contact_person',
        'phone',
        'email',
        'address_line',
        'country_code',
        'status',
        'status_reason',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

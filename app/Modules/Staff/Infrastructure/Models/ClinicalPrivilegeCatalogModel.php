<?php

namespace App\Modules\Staff\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ClinicalPrivilegeCatalogModel extends Model
{
    use HasUuids;

    protected $table = 'clinical_privilege_catalogs';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'specialty_id',
        'code',
        'name',
        'description',
        'cadre_code',
        'facility_type',
        'status',
        'status_reason',
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

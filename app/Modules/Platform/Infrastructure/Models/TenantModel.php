<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantModel extends Model
{
    use HasUuids;

    protected $table = 'tenants';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'name',
        'allowed_country_codes',
        'country_code',
        'status',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'allowed_country_codes' => 'array',
    ];
}

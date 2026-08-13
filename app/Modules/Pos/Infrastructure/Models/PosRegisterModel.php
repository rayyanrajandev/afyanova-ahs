<?php

namespace App\Modules\Pos\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosRegisterModel extends Model
{
    use HasUuids;

    protected $table = 'pos_registers';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'register_code',
        'register_name',
        'location',
        'default_currency_code',
        'status',
        'status_reason',
        'notes',
        'created_by_user_id',
        'updated_by_user_id',
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

    public function sessions(): HasMany
    {
        return $this->hasMany(PosRegisterSessionModel::class, 'pos_register_id');
    }
}

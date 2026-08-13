<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FeatureFlagOverrideModel extends Model
{
    use HasUuids;

    protected $table = 'feature_flag_overrides';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'flag_name',
        'scope_type',
        'scope_key',
        'enabled',
        'reason',
        'metadata',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'enabled' => 'boolean',
        'metadata' => 'array',
    ];
}

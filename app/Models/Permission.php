<?php

namespace App\Models;

use App\Modules\Platform\Infrastructure\Models\RoleModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * @return BelongsToMany<RoleModel, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            related: RoleModel::class,
            table: 'permission_role',
            foreignPivotKey: 'permission_id',
            relatedPivotKey: 'role_id',
        );
    }
}

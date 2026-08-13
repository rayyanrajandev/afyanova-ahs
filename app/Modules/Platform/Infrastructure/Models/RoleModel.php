<?php

namespace App\Modules\Platform\Infrastructure\Models;

use App\Models\Permission;
use App\Models\User;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RoleModel extends Model
{
    use HasUuids;

    protected $table = 'roles';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function booted(): void
    {
        static::creating(function (RoleModel $role): void {
            if ($role->effective_from === null) {
                $role->effective_from = now();
            }
        });
    }

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'department_id',
        'code',
        'name',
        'status',
        'description',
        'is_system',
        'access_level',
        'scope_type',
        'effective_from',
        'effective_until',
        'revoked_at',
        'revocation_reason',
        'related_department_ids',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'revoked_at' => 'datetime',
            'related_department_ids' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            related: Permission::class,
            table: 'permission_role',
            foreignPivotKey: 'role_id',
            relatedPivotKey: 'permission_id',
        );
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            related: User::class,
            table: 'role_user',
            foreignPivotKey: 'role_id',
            relatedPivotKey: 'user_id',
        );
    }

    /**
     * Department relationship for department-scoped roles
     *
     * @return BelongsTo<DepartmentModel, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(
            related: DepartmentModel::class,
            foreignKey: 'department_id',
        );
    }

    /**
     * Tenant relationship
     *
     * @return BelongsTo<TenantModel, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(
            related: TenantModel::class,
            foreignKey: 'tenant_id',
        );
    }

    /**
     * Facility relationship
     *
     * @return BelongsTo<FacilityModel, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(
            related: FacilityModel::class,
            foreignKey: 'facility_id',
        );
    }

    /**
     * Scope: Only active roles (not expired, not revoked)
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(function (Builder $q): void {
                $q->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', now());
            })
            ->whereNull('revoked_at');
    }

    /**
     * Scope: Only roles with an explicit access_level (inventory access roles)
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeInventoryAccess(Builder $query): Builder
    {
        return $query->whereNotNull('access_level');
    }

    /**
     * Scope: Not expired
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('effective_until')
                ->orWhere('effective_until', '>=', now());
        });
    }

    /**
     * Scope: For a specific department
     *
     * @param Builder $query
     * @param string $departmentId
     * @return Builder
     */
    public function scopeForDepartment(Builder $query, string $departmentId): Builder
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * Scope: For a specific access level
     *
     * @param Builder $query
     * @param string $accessLevel
     * @return Builder
     */
    public function scopeWithAccessLevel(Builder $query, string $accessLevel): Builder
    {
        return $query->where('access_level', $accessLevel);
    }

    /**
     * Check if role is currently active
     *
     * @return bool
     */
    public function isCurrentlyActive(): bool
    {
        return $this->status === 'active'
            && (!$this->effective_until || $this->effective_until->isFuture())
            && !$this->revoked_at;
    }

    /**
     * Check if role has expired
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->effective_until && $this->effective_until->isPast();
    }

    /**
     * Get related departments if scope is 'related_departments'
     *
     * @return \Illuminate\Support\Collection
     */
    public function getRelatedDepartments()
    {
        if ($this->scope_type !== 'related_departments' || !$this->related_department_ids) {
            return collect();
        }

        return DepartmentModel::whereIn('id', $this->related_department_ids)->get();
    }
}


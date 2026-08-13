<?php

namespace App\Models;

use App\Notifications\UserEmailVerificationNotification;
use App\Notifications\UserCredentialLinkNotification;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * @var array<int, string>
     */
    private const PLATFORM_SUPER_ADMIN_ROLE_CODES = [
        'PLATFORM.SUPER.ADMIN',
        'SYSTEM.SUPER.ADMIN',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'status',
        'status_reason',
        'deactivated_at',
        'is_platform_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'is_platform_admin' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /**
     * @return BelongsToMany<RoleModel, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            related: RoleModel::class,
            table: 'role_user',
            foreignPivotKey: 'user_id',
            relatedPivotKey: 'role_id',
        );
    }

    /**
     * Inventory access roles (department-scoped)
     * For Phase 1: Department-Level RBAC
     *
     * @return BelongsToMany<RoleModel, $this>
     */
    public function inventoryAccessRoles(): BelongsToMany
    {
        return $this->belongsToMany(
            related: RoleModel::class,
            table: 'role_user',
            foreignPivotKey: 'user_id',
            relatedPivotKey: 'role_id',
        )->whereNotNull('access_level'); // Only roles with access_level are inventory access roles
    }

    /**
     * Staff profile relationship
     * Links user to their professional/employment identity
     *
     * @return HasOne<StaffProfileModel, $this>
     */
    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfileModel::class, 'user_id', 'id');
    }

    public function hasPermissionTo(string $permission): bool
    {
        // Only a true platform-wide super admin gets every permission for free.
        // A facility-scoped admin (isFacilitySuperAdmin()) must hold the actual
        // permission via their role's grants, like anyone else — see
        // RBAC_Remediation_Plan.md Phase 2 for why hasUniversalAdminAccess()
        // (which also covers facility admins) is intentionally NOT used here.
        if ($this->isPlatformSuperAdmin()) {
            return true;
        }

        // Check direct permissions with expiration validation
        if ($this->relationLoaded('permissions')) {
            $permission = $this->permissions->firstWhere('name', $permission);
            if ($permission !== null) {
                $pivot = $permission->pivot;
                if ($pivot !== null && property_exists($pivot, 'expires_at') && $pivot->expires_at !== null) {
                    if (now()->greaterThan($pivot->expires_at)) {
                        // Expired direct permission — clean up and return false
                        $this->permissions()->detach($permission->id);
                        $this->unsetRelation('permissions');
                        return false;
                    }
                }
                return true;
            }
        }

        $directPermission = $this->permissions()
            ->where('name', $permission)
            ->where(function ($query) {
                $query->whereNull('permission_user.expires_at')
                    ->orWhere('permission_user.expires_at', '>', now());
            })
            ->first();

        if ($directPermission !== null) {
            return true;
        }

        try {
            return $this->roles()
                ->active()
                ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
                ->exists();
        } catch (QueryException) {
            return false;
        }
    }

    public function givePermissionTo(string $permission, ?\Carbon\Carbon $expiresAt = null): void
    {
        $permissionModel = Permission::query()->firstOrCreate([
            'name' => $permission,
        ]);

        $this->permissions()->syncWithoutDetaching([$permissionModel->id]);
        
        // Set expiration on the pivot if provided (default: 24 hours for security)
        if ($expiresAt === null) {
            $expiresAt = now()->addHours(24);
        }
        
        $this->permissions()->updateExistingPivot($permissionModel->id, [
            'expires_at' => $expiresAt,
        ]);
        
        $this->unsetRelation('permissions');
    }

    /**
     * @return array<int, string>
     */
    public function permissionNames(): array
    {
        // See hasPermissionTo() — platform-only, not hasUniversalAdminAccess().
        if ($this->isPlatformSuperAdmin()) {
            return Permission::query()
                ->orderBy('name')
                ->pluck('name')
                ->all();
        }

        $directPermissionNames = $this->permissions()
            ->pluck('name')
            ->all();

        try {
            $rolePermissionNames = Permission::query()
                ->select('permissions.name')
                ->join('permission_role', 'permissions.id', '=', 'permission_role.permission_id')
                ->join('role_user', 'permission_role.role_id', '=', 'role_user.role_id')
                ->join('roles', 'roles.id', '=', 'permission_role.role_id')
                ->where('role_user.user_id', $this->id)
                ->where('roles.status', 'active')
                ->whereNull('roles.revoked_at')
                ->where(function ($q) {
                    $q->whereNull('roles.effective_until')
                        ->orWhere('roles.effective_until', '>=', now());
                })
                ->pluck('permissions.name')
                ->all();
        } catch (QueryException) {
            $rolePermissionNames = [];
        }

        $allPermissionNames = array_values(array_unique(array_merge($directPermissionNames, $rolePermissionNames)));
        sort($allPermissionNames);

        return $allPermissionNames;
    }

    /**
     * Role codes resolved from active RBAC memberships (uppercase codes only).
     *
     * @return array<int, string>
     */
    public function roleCodes(): array
    {
        try {
            /** @var list<string> $codes */
            $codes = $this->roles()
                ->where('roles.status', 'active')
                ->pluck('roles.code')
                ->all();

            $normalized = array_values(array_unique(array_filter(array_map(
                static fn (mixed $code): string => strtoupper(trim((string) $code)),
                $codes,
            ))));
            sort($normalized);

            return $normalized;
        } catch (QueryException) {
            return [];
        }
    }

    public function isFacilitySuperAdminAccess(): bool
    {
        return $this->hasUniversalAdminAccess();
    }

    public function hasFacilityAssignments(): bool
    {
        try {
            return DB::table('facility_user')
                ->where('user_id', $this->id)
                ->exists();
        } catch (QueryException) {
            return false;
        }
    }

    public function isPlatformSuperAdminAccess(): bool
    {
        return $this->isPlatformSuperAdmin();
    }

    public function hasUniversalAdminAccess(): bool
    {
        return $this->isPlatformSuperAdmin() || $this->isFacilitySuperAdmin();
    }

    private function isPlatformSuperAdmin(): bool
    {
        if ($this->is_platform_admin) {
            return true;
        }

        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(function (mixed $role): bool {
                $code = strtoupper(trim((string) ($role->code ?? '')));

                return in_array($code, self::PLATFORM_SUPER_ADMIN_ROLE_CODES, true)
                    && $role instanceof RoleModel
                    && $role->isCurrentlyActive();
            });
        }

        try {
            return $this->roles()
                ->whereIn('code', self::PLATFORM_SUPER_ADMIN_ROLE_CODES)
                ->active()
                ->exists();
        } catch (QueryException) {
            return false;
        }
    }

    private function isFacilitySuperAdmin(): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(function (mixed $role): bool {
                $code = strtoupper(trim((string) ($role->code ?? '')));

                return $code === 'ADMIN.FACILITY'
                    && $role instanceof RoleModel
                    && $role->isCurrentlyActive();
            });
        }

        try {
            return $this->roles()
                ->where('code', 'ADMIN.FACILITY')
                ->active()
                ->exists();
        } catch (QueryException) {
            return false;
        }
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new UserCredentialLinkNotification($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new UserEmailVerificationNotification());
    }
}



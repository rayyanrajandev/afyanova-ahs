<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $permissions = [
        'platform.subscription-plans.read',
        'platform.subscription-plans.manage',
        'platform.subscription-plans.view-audit-logs',
    ];

    /**
     * @var array<int, string>
     */
    private array $subscriptionRolePermissions = [
        'platform.facilities.read',
        'platform.facilities.manage-subscriptions',
        'platform.facilities.view-audit-logs',
    ];

    public function up(): void
    {
        Schema::create('platform_subscription_plan_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('plan_id')->nullable();
            $table->foreignId('actor_id')->nullable();
            $table->string('action', 120);
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['plan_id', 'created_at'], 'platform_subscription_plan_audit_logs_plan_created_idx');
            $table->index(['action', 'created_at'], 'platform_subscription_plan_audit_logs_action_created_idx');

            $table->foreign('plan_id')
                ->references('id')
                ->on('platform_subscription_plans')
                ->nullOnDelete();
            $table->foreign('actor_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        $this->seedPermissions();
    }

    public function down(): void
    {
        $this->removePermissions();

        Schema::dropIfExists('platform_subscription_plan_audit_logs');
    }

    private function seedPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $permissionIds = [];

        $permissions = array_values(array_unique(array_merge($this->permissions, $this->subscriptionRolePermissions)));

        foreach ($permissions as $permission) {
            $permissionId = DB::table('permissions')
                ->where('name', $permission)
                ->value('id');

            if ($permissionId === null) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => $permission,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('permissions')
                    ->where('id', $permissionId)
                    ->update(['updated_at' => $now]);
            }

            $permissionIds[] = $permissionId;
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $roleIds = DB::table('roles')
            ->whereIn('code', ['PLATFORM.USER.ADMIN', 'PLATFORM.SUBSCRIPTION.ADMIN'])
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            $roleIds = collect();
        }

        $subscriptionRoleId = DB::table('roles')
            ->where('code', 'PLATFORM.SUBSCRIPTION.ADMIN')
            ->value('id');

        if ($subscriptionRoleId === null) {
            $subscriptionRoleId = (string) \Illuminate\Support\Str::uuid();
            DB::table('roles')->insert([
                'id' => $subscriptionRoleId,
                'tenant_id' => null,
                'facility_id' => null,
                'code' => 'PLATFORM.SUBSCRIPTION.ADMIN',
                'name' => 'Platform Subscription Administrator',
                'status' => 'active',
                'description' => 'Manages service plans, facility subscription assignment, and subscription audit review.',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $roleIds->push($subscriptionRoleId);
        }

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(
                    [
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ],
                    [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    private function removePermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissions = array_values(array_unique(array_merge($this->permissions, $this->subscriptionRolePermissions)));
        $permissionIds = DB::table('permissions')
            ->whereIn('name', $permissions)
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('roles') && Schema::hasTable('permission_role')) {
            $roleIds = DB::table('roles')
                ->whereIn('code', ['PLATFORM.USER.ADMIN', 'PLATFORM.SUBSCRIPTION.ADMIN'])
                ->pluck('id');

            DB::table('permission_role')
                ->whereIn('permission_id', $permissionIds)
                ->whereIn('role_id', $roleIds)
                ->delete();
        }
    }
};

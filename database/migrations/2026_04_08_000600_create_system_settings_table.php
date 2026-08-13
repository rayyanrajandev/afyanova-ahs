<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('group', 80)->default('general');
            $table->string('key', 120)->unique();
            $table->longText('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->timestamps();

            $table->index(['group', 'key']);
        });

        if (Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')
                ->where('name', 'platform.settings.manage-branding')
                ->value('id');

            if ($permissionId === null) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => 'platform.settings.manage-branding',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (Schema::hasTable('roles') && Schema::hasTable('permission_role')) {
                $roleIds = DB::table('roles')
                    ->whereIn('code', [
                        'PLATFORM.RBAC.ADMIN',
                        'HOSPITAL.FACILITY.ADMIN',
                    ])
                    ->pluck('id')
                    ->all();

                foreach ($roleIds as $roleId) {
                    $exists = DB::table('permission_role')
                        ->where('permission_id', $permissionId)
                        ->where('role_id', $roleId)
                        ->exists();

                    if (! $exists) {
                        DB::table('permission_role')->insert([
                            'permission_id' => $permissionId,
                            'role_id' => $roleId,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')
                ->where('name', 'platform.settings.manage-branding')
                ->value('id');

            if ($permissionId !== null && Schema::hasTable('permission_role')) {
                DB::table('permission_role')
                    ->where('permission_id', $permissionId)
                    ->delete();
            }

            if ($permissionId !== null) {
                DB::table('permissions')
                    ->where('id', $permissionId)
                    ->delete();
            }
        }

        Schema::dropIfExists('system_settings');
    }
};

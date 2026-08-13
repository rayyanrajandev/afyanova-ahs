<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * New permission for the observation-room registry (dispensary/health-centre
 * facilities without full wards). Seeds only the bare permission row --
 * granting it to real roles is config/roles.php's job via `roles:sync`
 * (kept as a separate concern from this migration, same convention as the
 * rest of the permission catalog).
 */
return new class extends Migration
{
    private const NEW_PERMISSION = 'platform.resources.manage-observation-rooms';

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $exists = DB::table('permissions')->where('name', self::NEW_PERMISSION)->exists();
        if ($exists) {
            return;
        }

        $now = now();

        DB::table('permissions')->insert([
            'name' => self::NEW_PERMISSION,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permission_role')
            ->whereIn('permission_id', function ($query): void {
                $query->select('id')
                    ->from('permissions')
                    ->where('name', self::NEW_PERMISSION);
            })
            ->delete();

        DB::table('permissions')->where('name', self::NEW_PERMISSION)->delete();
    }
};

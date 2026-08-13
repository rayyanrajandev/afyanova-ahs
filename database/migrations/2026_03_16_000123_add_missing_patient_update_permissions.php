<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $timestamp = now();

        foreach (['patients.update', 'patients.update-status'] as $permissionName) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permissionName],
                ['created_at' => $timestamp, 'updated_at' => $timestamp],
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('name', ['patients.update', 'patients.update-status'])
            ->delete();
    }
};

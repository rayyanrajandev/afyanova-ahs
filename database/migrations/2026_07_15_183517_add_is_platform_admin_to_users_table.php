<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false)->after('email_verified_at');
        });

        // Seed: anyone with facility_user.role='super_admin' is a platform admin
        DB::table('users')
            ->whereIn('id', function ($query): void {
                $query->select('user_id')
                    ->from('facility_user')
                    ->where('role', 'super_admin')
                    ->where('is_active', true);
            })
            ->update(['is_platform_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_platform_admin');
        });
    }
};

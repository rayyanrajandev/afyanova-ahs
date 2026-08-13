<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('staff_profiles', 'department_id')) {
                $table->uuid('department_id')->nullable()->after('department');
                $table->index('department_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table): void {
            if (Schema::hasColumn('staff_profiles', 'department_id')) {
                $table->dropIndex(['department_id']);
                $table->dropColumn('department_id');
            }
        });
    }
};

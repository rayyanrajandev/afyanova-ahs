<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('admissions', 'discharge_destination')) {
                $table->string('discharge_destination', 120)->nullable()->after('status_reason');
            }

            if (! Schema::hasColumn('admissions', 'follow_up_plan')) {
                $table->text('follow_up_plan')->nullable()->after('discharge_destination');
            }
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            if (Schema::hasColumn('admissions', 'follow_up_plan')) {
                $table->dropColumn('follow_up_plan');
            }

            if (Schema::hasColumn('admissions', 'discharge_destination')) {
                $table->dropColumn('discharge_destination');
            }
        });
    }
};

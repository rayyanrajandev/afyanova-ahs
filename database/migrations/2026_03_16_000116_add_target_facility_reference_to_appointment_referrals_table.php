<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_referrals', function (Blueprint $table): void {
            if (! Schema::hasColumn('appointment_referrals', 'target_facility_id')) {
                $table->uuid('target_facility_id')->nullable()->after('target_department');
                $table->index('target_facility_id');
            }

            if (! Schema::hasColumn('appointment_referrals', 'target_facility_code')) {
                $table->string('target_facility_code', 30)->nullable()->after('target_facility_id');
                $table->index('target_facility_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointment_referrals', function (Blueprint $table): void {
            if (Schema::hasColumn('appointment_referrals', 'target_facility_code')) {
                $table->dropIndex(['target_facility_code']);
                $table->dropColumn('target_facility_code');
            }

            if (Schema::hasColumn('appointment_referrals', 'target_facility_id')) {
                $table->dropIndex(['target_facility_id']);
                $table->dropColumn('target_facility_id');
            }
        });
    }
};


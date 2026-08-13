<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->timestamp('checked_in_at')->nullable()->after('status_reason');
        });

        // Backfill: approximate check-in time from triaged_at for records that
        // have already progressed past SCHEDULED (triaged_at is the best proxy
        // for when the patient arrived; fall back to updated_at otherwise).
        DB::statement("
            UPDATE appointments
            SET checked_in_at = COALESCE(triaged_at, updated_at)
            WHERE status NOT IN ('scheduled', 'cancelled', 'no_show')
              AND checked_in_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('checked_in_at');
        });
    }
};

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
            if (! Schema::hasColumn('appointments', 'triage_vitals_summary')) {
                $table->text('triage_vitals_summary')->nullable()->after('status_reason');
            }
            if (! Schema::hasColumn('appointments', 'triage_notes')) {
                $table->text('triage_notes')->nullable()->after('triage_vitals_summary');
            }
            if (! Schema::hasColumn('appointments', 'triaged_at')) {
                $table->timestamp('triaged_at')->nullable()->after('triage_notes');
            }
            if (! Schema::hasColumn('appointments', 'triaged_by_user_id')) {
                $table->foreignId('triaged_by_user_id')->nullable()->after('triaged_at')->constrained('users')->nullOnDelete();
            }
        });

        DB::table('appointments')
            ->where('status', 'checked_in')
            ->update([
                'status' => 'waiting_triage',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('appointments')
            ->where('status', 'waiting_triage')
            ->update([
                'status' => 'checked_in',
                'updated_at' => now(),
            ]);

        Schema::table('appointments', function (Blueprint $table): void {
            if (Schema::hasColumn('appointments', 'triaged_by_user_id')) {
                $table->dropConstrainedForeignId('triaged_by_user_id');
            }
            if (Schema::hasColumn('appointments', 'triaged_at')) {
                $table->dropColumn('triaged_at');
            }
            if (Schema::hasColumn('appointments', 'triage_notes')) {
                $table->dropColumn('triage_notes');
            }
            if (Schema::hasColumn('appointments', 'triage_vitals_summary')) {
                $table->dropColumn('triage_vitals_summary');
            }
        });
    }
};

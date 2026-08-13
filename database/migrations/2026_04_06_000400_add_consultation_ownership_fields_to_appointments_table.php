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
            if (! Schema::hasColumn('appointments', 'consultation_started_at')) {
                $table->timestamp('consultation_started_at')->nullable()->after('triaged_by_user_id');
            }

            if (! Schema::hasColumn('appointments', 'consultation_owner_user_id')) {
                $table->foreignId('consultation_owner_user_id')
                    ->nullable()
                    ->after('consultation_started_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('appointments', 'consultation_owner_assigned_at')) {
                $table->timestamp('consultation_owner_assigned_at')
                    ->nullable()
                    ->after('consultation_owner_user_id');
            }

            if (! Schema::hasColumn('appointments', 'consultation_takeover_count')) {
                $table->unsignedSmallInteger('consultation_takeover_count')
                    ->default(0)
                    ->after('consultation_owner_assigned_at');
            }
        });

        $now = now();

        DB::table('appointments')
            ->where('status', 'in_consultation')
            ->whereNull('consultation_started_at')
            ->update([
                'consultation_started_at' => $now,
                'updated_at' => $now,
            ]);

        DB::table('appointments')
            ->where('status', 'in_consultation')
            ->whereNull('consultation_owner_user_id')
            ->whereNotNull('clinician_user_id')
            ->update([
                'consultation_owner_user_id' => DB::raw('clinician_user_id'),
                'consultation_owner_assigned_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            if (Schema::hasColumn('appointments', 'consultation_takeover_count')) {
                $table->dropColumn('consultation_takeover_count');
            }

            if (Schema::hasColumn('appointments', 'consultation_owner_assigned_at')) {
                $table->dropColumn('consultation_owner_assigned_at');
            }

            if (Schema::hasColumn('appointments', 'consultation_owner_user_id')) {
                $table->dropConstrainedForeignId('consultation_owner_user_id');
            }

            if (Schema::hasColumn('appointments', 'consultation_started_at')) {
                $table->dropColumn('consultation_started_at');
            }
        });
    }
};


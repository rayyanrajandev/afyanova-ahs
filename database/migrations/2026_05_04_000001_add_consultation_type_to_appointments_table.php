<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('appointments')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table): void {
            // consultation_type classifies whether this is a brand-new clinical episode
            // or a return visit within the follow-up window for the same complaint.
            // 'new'    = first presentation or new clinical episode (default).
            // 'review' = return within the configured follow-up window for the same complaint.
            if (! Schema::hasColumn('appointments', 'consultation_type')) {
                $table->string('consultation_type', 20)
                    ->default('new')
                    ->after('appointment_type');
            }

            // consultation_type_source records how the classification was determined.
            // 'auto'   = classified automatically by system rules on appointment creation.
            // 'manual' = overridden by a staff member after creation.
            if (! Schema::hasColumn('appointments', 'consultation_type_source')) {
                $table->string('consultation_type_source', 20)
                    ->default('auto')
                    ->after('consultation_type');
            }

            // Populated when a staff member manually changes the classification.
            // Mandatory when consultation_type_source = 'manual'.
            if (! Schema::hasColumn('appointments', 'consultation_type_override_reason')) {
                $table->string('consultation_type_override_reason', 500)
                    ->nullable()
                    ->after('consultation_type_source');
            }

            // References the prior completed appointment that triggered a REVIEW classification.
            // NULL for new consultations or when the prior appointment cannot be found.
            if (! Schema::hasColumn('appointments', 'prior_completed_appointment_id')) {
                $table->uuid('prior_completed_appointment_id')
                    ->nullable()
                    ->after('consultation_type_override_reason');
            }
        });

        // Backfill: all existing completed or active appointments are treated as NEW
        // since there was no classification before. The column default handles this.
    }

    public function down(): void
    {
        if (! Schema::hasTable('appointments')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('appointments', 'consultation_type') ? 'consultation_type' : null,
            Schema::hasColumn('appointments', 'consultation_type_source') ? 'consultation_type_source' : null,
            Schema::hasColumn('appointments', 'consultation_type_override_reason') ? 'consultation_type_override_reason' : null,
            Schema::hasColumn('appointments', 'prior_completed_appointment_id') ? 'prior_completed_appointment_id' : null,
        ]));

        if ($columns === []) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};

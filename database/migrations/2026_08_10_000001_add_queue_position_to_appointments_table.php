<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Volume 2.1 §10.3 "Reorder" / Volume 3.7 T5.5 — a nullable manual-order
 * override, not a synced/duplicated queue projection (matches
 * GetReceptionQueueUseCase's own "live query, no drift risk" principle —
 * one column on the same row already being queried, not a second table
 * that could fall out of sync with it).
 *
 * Only meaningful within the appointment's *current* status/stage: reset to
 * null on every status change (UpdateAppointmentStatusUseCase) so a manual
 * reorder from an earlier stage never silently reappears in a later one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->unsignedInteger('queue_position')->nullable()->after('status_reason');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('queue_position');
        });
    }
};

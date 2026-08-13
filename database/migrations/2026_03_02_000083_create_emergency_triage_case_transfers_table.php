<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('emergency_triage_case_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('emergency_triage_case_id');
            $table->string('transfer_number')->unique();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('transfer_type', 30)->default('internal');
            $table->string('priority', 20)->default('urgent');
            $table->string('source_location', 180)->nullable();
            $table->string('destination_location', 180);
            $table->string('destination_facility_name', 180)->nullable();
            $table->foreignId('accepting_clinician_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 30)->default('requested');
            $table->string('status_reason')->nullable();
            $table->text('clinical_handoff_notes')->nullable();
            $table->string('transport_mode', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['emergency_triage_case_id', 'requested_at'], 'em_triage_transfer_case_requested_idx');
            $table->index(['tenant_id', 'requested_at'], 'em_triage_transfer_tenant_requested_idx');
            $table->index(['facility_id', 'requested_at'], 'em_triage_transfer_facility_requested_idx');
            $table->index(['status', 'requested_at'], 'em_triage_transfer_status_requested_idx');
            $table->index(['transfer_type', 'priority'], 'em_triage_transfer_type_priority_idx');

            $table->foreign('emergency_triage_case_id', 'em_triage_transfer_case_fk')
                ->references('id')
                ->on('emergency_triage_cases')
                ->cascadeOnDelete();

            $table->foreign('tenant_id', 'em_triage_transfer_tenant_fk')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id', 'em_triage_transfer_facility_fk')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emergency_triage_case_transfers');
    }
};

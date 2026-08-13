<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_procedure_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('order_number')->unique();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('patient_id');
            $table->uuid('encounter_id')->nullable();
            $table->uuid('admission_id')->nullable();
            $table->uuid('appointment_id')->nullable();
            $table->uuid('clinical_order_session_id')->nullable();
            $table->uuid('replaces_order_id')->nullable();
            $table->uuid('add_on_to_order_id')->nullable();
            $table->foreignId('ordered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ordered_at');
            $table->uuid('clinical_procedure_catalog_item_id')->nullable();
            $table->string('procedure_code', 100)->nullable();
            $table->string('procedure_setting', 30)->nullable();
            $table->string('procedure_description', 255)->nullable();
            $table->text('clinical_indication')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->text('report_summary')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 30)->default('ordered');
            $table->string('entry_state', 20)->default('active');
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status_reason')->nullable();
            $table->string('lifecycle_reason_code', 40)->nullable();
            $table->timestamp('entered_in_error_at')->nullable();
            $table->foreignId('entered_in_error_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifecycle_locked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'ordered_at']);
            $table->index(['facility_id', 'ordered_at']);
            $table->index(['facility_id', 'status']);
            $table->index(['patient_id', 'ordered_at']);
            $table->index(['status', 'ordered_at']);
            $table->index(['status', 'lifecycle_reason_code']);
            $table->index(['entry_state', 'status']);
            $table->index('clinical_procedure_catalog_item_id');
            $table->index('procedure_code');
            $table->index('encounter_id');
            $table->index('clinical_order_session_id');
            $table->index('replaces_order_id');
            $table->index('add_on_to_order_id');
            $table->index('procedure_setting');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();

            $table->foreign('patient_id')
                ->references('id')
                ->on('patients')
                ->cascadeOnDelete();

            $table->foreign('encounter_id')
                ->references('id')
                ->on('encounters')
                ->nullOnDelete();

            $table->foreign('admission_id')
                ->references('id')
                ->on('admissions')
                ->nullOnDelete();

            $table->foreign('appointment_id')
                ->references('id')
                ->on('appointments')
                ->nullOnDelete();

            $table->foreign('clinical_order_session_id')
                ->references('id')
                ->on('clinical_order_sessions')
                ->nullOnDelete();

            $table->foreign('clinical_procedure_catalog_item_id')
                ->references('id')
                ->on('platform_clinical_catalog_items')
                ->nullOnDelete();
        });

        Schema::table('clinical_procedure_orders', function (Blueprint $table): void {
            $table->foreign('replaces_order_id')
                ->references('id')
                ->on('clinical_procedure_orders')
                ->nullOnDelete();

            $table->foreign('add_on_to_order_id')
                ->references('id')
                ->on('clinical_procedure_orders')
                ->nullOnDelete();
        });

        Schema::create('clinical_procedure_order_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('clinical_procedure_order_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['clinical_procedure_order_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('clinical_procedure_order_id')
                ->references('id')
                ->on('clinical_procedure_orders')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_procedure_order_audit_logs');
        Schema::dropIfExists('clinical_procedure_orders');
    }
};

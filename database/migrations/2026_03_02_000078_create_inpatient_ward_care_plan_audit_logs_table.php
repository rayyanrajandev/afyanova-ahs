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
        Schema::create('inpatient_ward_care_plan_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inpatient_ward_care_plan_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['inpatient_ward_care_plan_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('inpatient_ward_care_plan_id')
                ->references('id')
                ->on('inpatient_ward_care_plans')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inpatient_ward_care_plan_audit_logs');
    }
};


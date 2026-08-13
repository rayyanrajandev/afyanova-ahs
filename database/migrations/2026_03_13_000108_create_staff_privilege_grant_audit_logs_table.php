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
        Schema::create('staff_privilege_grant_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('staff_privilege_grant_id');
            $table->uuid('staff_profile_id');
            $table->string('action');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['staff_privilege_grant_id', 'created_at'], 'staff_privilege_grant_audit_logs_grant_created_idx');
            $table->index(['staff_profile_id', 'created_at'], 'staff_privilege_grant_audit_logs_profile_created_idx');
            $table->index(['action', 'created_at'], 'staff_privilege_grant_audit_logs_action_created_idx');
            $table->index(['actor_id', 'created_at'], 'staff_privilege_grant_audit_logs_actor_created_idx');

            $table->foreign('staff_privilege_grant_id')
                ->references('id')
                ->on('staff_privilege_grants')
                ->cascadeOnDelete();

            $table->foreign('staff_profile_id')
                ->references('id')
                ->on('staff_profiles')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_privilege_grant_audit_logs');
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_approval_workflows', function (Blueprint $table): void {
            $table->integer('version')->default(1)->after('description');
            $table->index(['code', 'version']);
        });

        Schema::table('inventory_approval_workflow_instances', function (Blueprint $table): void {
            $table->integer('workflow_version')->default(1)->after('total_steps');
            $table->index(['workflow_id', 'workflow_version']);
        });

        Schema::create('inventory_approval_workflow_version_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id');
            $table->integer('version_number');
            $table->string('change_type', 50); // created, updated, approval_steps_modified, trigger_rules_modified, status_changed
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('changed_by_user_type', 30); // user, system, migration
            $table->uuid('changed_by_user_id')->nullable();
            $table->text('change_reason')->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();

            $table->index(['workflow_id', 'version_number']);
            $table->index(['change_type', 'changed_at']);
            $table->index(['changed_by_user_id']);

            $table->foreign('workflow_id')->references('id')->on('inventory_approval_workflows')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_approval_workflow_version_changes');

        Schema::table('inventory_approval_workflow_instances', function (Blueprint $table): void {
            $table->dropIndex(['workflow_id', 'workflow_version']);
            $table->dropColumn('workflow_version');
        });

        Schema::table('inventory_approval_workflows', function (Blueprint $table): void {
            $table->dropIndex(['code', 'version']);
            $table->dropColumn('version');
        });
    }
};

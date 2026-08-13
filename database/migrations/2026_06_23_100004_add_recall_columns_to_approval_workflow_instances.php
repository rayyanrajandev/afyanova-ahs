<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_approval_workflow_instances', function (Blueprint $table): void {
            $table->uuid('recalled_decision_id')->nullable()->after('rejected_at');
            $table->text('recall_reason')->nullable()->after('recalled_decision_id');

            $table->foreign('recalled_decision_id')
                ->references('id')
                ->on('inventory_approval_decisions')
                ->nullOnDelete();

            $table->index(['recalled_decision_id']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_approval_workflow_instances', function (Blueprint $table): void {
            $table->dropForeign(['recalled_decision_id']);
            $table->dropIndex(['recalled_decision_id']);
            $table->dropColumn(['recalled_decision_id', 'recall_reason']);
        });
    }
};

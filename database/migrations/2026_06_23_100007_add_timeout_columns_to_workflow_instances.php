<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_approval_workflow_instances', function (Blueprint $table): void {
            $table->timestamp('timeout_at')->nullable()->after('rejected_at');
            $table->timestamp('auto_rejected_at')->nullable()->after('timeout_at');
            $table->index(['status', 'timeout_at']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_approval_workflow_instances', function (Blueprint $table): void {
            $table->dropIndex(['status', 'timeout_at']);
            $table->dropColumn('auto_rejected_at');
            $table->dropColumn('timeout_at');
        });
    }
};

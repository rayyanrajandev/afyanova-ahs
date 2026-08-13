<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addEntryStateColumns('pharmacy_orders', 'COALESCE(lifecycle_locked_at, ordered_at, created_at, CURRENT_TIMESTAMP)');
        $this->addEntryStateColumns('laboratory_orders', 'COALESCE(lifecycle_locked_at, ordered_at, created_at, CURRENT_TIMESTAMP)');
        $this->addEntryStateColumns('radiology_orders', 'COALESCE(lifecycle_locked_at, ordered_at, created_at, CURRENT_TIMESTAMP)');
        $this->addEntryStateColumns('theatre_procedures', 'COALESCE(lifecycle_locked_at, scheduled_at, created_at, CURRENT_TIMESTAMP)');
    }

    public function down(): void
    {
        $this->dropEntryStateColumns('pharmacy_orders');
        $this->dropEntryStateColumns('laboratory_orders');
        $this->dropEntryStateColumns('radiology_orders');
        $this->dropEntryStateColumns('theatre_procedures');
    }

    private function addEntryStateColumns(string $tableName, string $signedTimestampSql): void
    {
        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('entry_state', 20)->default('active')->after('status');
            $table->timestamp('signed_at')->nullable()->after('entry_state');
            $table->foreignId('signed_by_user_id')->nullable()->after('signed_at')->constrained('users')->nullOnDelete();
            $table->index(['entry_state', 'status']);
        });

        DB::table($tableName)->update([
            'entry_state' => DB::raw("'active'"),
            'signed_at' => DB::raw($signedTimestampSql),
        ]);
    }

    private function dropEntryStateColumns(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropForeign(['signed_by_user_id']);
            $table->dropIndex(['entry_state', 'status']);
            $table->dropColumn([
                'entry_state',
                'signed_at',
                'signed_by_user_id',
            ]);
        });
    }
};

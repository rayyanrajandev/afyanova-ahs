<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addLifecycleColumns(
            table: 'laboratory_orders',
            previousContextColumn: 'appointment_id',
            lockTimestampSql: 'COALESCE(ordered_at, created_at, CURRENT_TIMESTAMP)',
        );
        $this->addLifecycleColumns(
            table: 'pharmacy_orders',
            previousContextColumn: 'appointment_id',
            lockTimestampSql: 'COALESCE(ordered_at, created_at, CURRENT_TIMESTAMP)',
        );
        $this->addLifecycleColumns(
            table: 'radiology_orders',
            previousContextColumn: 'appointment_id',
            lockTimestampSql: 'COALESCE(ordered_at, created_at, CURRENT_TIMESTAMP)',
        );
        $this->addLifecycleColumns(
            table: 'theatre_procedures',
            previousContextColumn: 'appointment_id',
            lockTimestampSql: 'COALESCE(scheduled_at, created_at, CURRENT_TIMESTAMP)',
        );
    }

    public function down(): void
    {
        $this->dropLifecycleColumns('laboratory_orders');
        $this->dropLifecycleColumns('pharmacy_orders');
        $this->dropLifecycleColumns('radiology_orders');
        $this->dropLifecycleColumns('theatre_procedures');
    }

    private function addLifecycleColumns(
        string $table,
        string $previousContextColumn,
        string $lockTimestampSql
    ): void {
        Schema::table($table, function (Blueprint $table) use ($previousContextColumn): void {
            $table->uuid('clinical_order_session_id')->nullable()->after($previousContextColumn);
            $table->uuid('replaces_order_id')->nullable()->after('clinical_order_session_id');
            $table->uuid('add_on_to_order_id')->nullable()->after('replaces_order_id');
            $table->string('lifecycle_reason_code', 40)->nullable()->after('status_reason');
            $table->timestamp('entered_in_error_at')->nullable()->after('lifecycle_reason_code');
            $table->foreignId('entered_in_error_by_user_id')->nullable()->after('entered_in_error_at')->constrained('users')->nullOnDelete();
            $table->timestamp('lifecycle_locked_at')->nullable()->after('entered_in_error_by_user_id');

            $table->index('clinical_order_session_id');
            $table->index('replaces_order_id');
            $table->index('add_on_to_order_id');
            $table->index(['status', 'lifecycle_reason_code']);

            $table->foreign('clinical_order_session_id')
                ->references('id')
                ->on('clinical_order_sessions')
                ->nullOnDelete();
            $table->foreign('replaces_order_id')
                ->references('id')
                ->on($table->getTable())
                ->nullOnDelete();
            $table->foreign('add_on_to_order_id')
                ->references('id')
                ->on($table->getTable())
                ->nullOnDelete();
        });

        DB::table($table)
            ->whereNull('lifecycle_locked_at')
            ->update([
                'lifecycle_locked_at' => DB::raw($lockTimestampSql),
            ]);
    }

    private function dropLifecycleColumns(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropForeign(['entered_in_error_by_user_id']);
            $table->dropForeign(['clinical_order_session_id']);
            $table->dropForeign(['replaces_order_id']);
            $table->dropForeign(['add_on_to_order_id']);
            $table->dropIndex(['clinical_order_session_id']);
            $table->dropIndex(['replaces_order_id']);
            $table->dropIndex(['add_on_to_order_id']);
            $table->dropIndex(['status', 'lifecycle_reason_code']);
            $table->dropColumn([
                'clinical_order_session_id',
                'replaces_order_id',
                'add_on_to_order_id',
                'lifecycle_reason_code',
                'entered_in_error_at',
                'entered_in_error_by_user_id',
                'lifecycle_locked_at',
            ]);
        });
    }
};

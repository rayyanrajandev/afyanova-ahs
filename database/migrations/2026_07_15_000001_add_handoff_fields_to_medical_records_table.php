<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('medical_records', 'handed_off_to_user_id')) {
                $table->foreignId('handed_off_to_user_id')
                    ->nullable()
                    ->after('author_user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('medical_records', 'handoff_initiated_by_user_id')) {
                $table->foreignId('handoff_initiated_by_user_id')
                    ->nullable()
                    ->after('handed_off_to_user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('medical_records', 'handoff_status')) {
                $table->string('handoff_status', 20)
                    ->nullable()
                    ->after('handoff_initiated_by_user_id');
            }

            if (! Schema::hasColumn('medical_records', 'handoff_note')) {
                $table->text('handoff_note')
                    ->nullable()
                    ->after('handoff_status');
            }

            if (! Schema::hasColumn('medical_records', 'handed_off_at')) {
                $table->timestamp('handed_off_at')
                    ->nullable()
                    ->after('handoff_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table): void {
            $columns = ['handed_off_to_user_id', 'handoff_initiated_by_user_id', 'handoff_status', 'handoff_note', 'handed_off_at'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('medical_records', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

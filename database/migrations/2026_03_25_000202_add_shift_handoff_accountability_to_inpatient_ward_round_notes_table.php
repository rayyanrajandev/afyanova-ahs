<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inpatient_ward_round_notes', function (Blueprint $table): void {
            $table->string('shift_label', 32)->nullable()->after('rounded_at');
            $table->foreignId('acknowledged_by_user_id')->nullable()->after('handoff_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable()->after('acknowledged_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('inpatient_ward_round_notes', function (Blueprint $table): void {
            $table->dropForeign(['acknowledged_by_user_id']);
            $table->dropColumn(['shift_label', 'acknowledged_by_user_id', 'acknowledged_at']);
        });
    }
};

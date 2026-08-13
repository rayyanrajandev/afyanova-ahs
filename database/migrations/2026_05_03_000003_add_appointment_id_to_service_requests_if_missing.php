<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_requests')) {
            return;
        }

        if (! Schema::hasColumn('service_requests', 'appointment_id')) {
            Schema::table('service_requests', function (Blueprint $table): void {
                $table->uuid('appointment_id')->nullable()->after('patient_id')->index();
                $table->foreign('appointment_id')
                    ->references('id')
                    ->on('appointments')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('service_requests') || ! Schema::hasColumn('service_requests', 'appointment_id')) {
            return;
        }

        Schema::table('service_requests', function (Blueprint $table): void {
            $table->dropForeign(['appointment_id']);
            $table->dropColumn('appointment_id');
        });
    }
};

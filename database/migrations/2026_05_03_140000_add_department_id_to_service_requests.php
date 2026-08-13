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

        if (Schema::hasColumn('service_requests', 'department_id')) {
            return;
        }

        Schema::table('service_requests', function (Blueprint $table): void {
            $table->uuid('department_id')->nullable()->after('appointment_id')->index();

            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('service_requests') || ! Schema::hasColumn('service_requests', 'department_id')) {
            return;
        }

        Schema::table('service_requests', function (Blueprint $table): void {
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });
    }
};

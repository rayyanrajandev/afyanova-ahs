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

        Schema::table('service_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('service_requests', 'linked_order_type')) {
                $table->string('linked_order_type', 64)->nullable()->after('status_reason');
            }

            if (! Schema::hasColumn('service_requests', 'linked_order_id')) {
                $table->uuid('linked_order_id')->nullable()->after('linked_order_type');
            }

            if (! Schema::hasColumn('service_requests', 'linked_order_number')) {
                $table->string('linked_order_number', 80)->nullable()->after('linked_order_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('service_requests')) {
            return;
        }

        Schema::table('service_requests', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('service_requests', 'linked_order_number') ? 'linked_order_number' : null,
                Schema::hasColumn('service_requests', 'linked_order_id') ? 'linked_order_id' : null,
                Schema::hasColumn('service_requests', 'linked_order_type') ? 'linked_order_type' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

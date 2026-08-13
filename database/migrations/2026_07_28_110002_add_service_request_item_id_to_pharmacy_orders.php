<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            $table->uuid('service_request_item_id')->nullable()->after('id');

            $table->foreign('service_request_item_id')
                ->references('id')
                ->on('service_request_items')
                ->nullOnDelete();

            $table->index('service_request_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            $table->dropForeign(['service_request_item_id']);
            $table->dropIndex(['service_request_item_id']);
            $table->dropColumn('service_request_item_id');
        });
    }
};

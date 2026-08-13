<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_request_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('service_request_id');
            $table->string('service_type', 32);
            $table->uuid('catalog_item_id')->nullable();
            $table->string('item_name');
            $table->string('item_code', 50)->nullable();
            $table->integer('quantity')->default(1);
            $table->string('status', 20)->default('pending')->index();
            $table->text('clinical_indication')->nullable();
            $table->text('instructions')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->foreign('service_request_id')
                ->references('id')
                ->on('service_requests')
                ->cascadeOnDelete();

            $table->foreign('catalog_item_id')
                ->references('id')
                ->on('platform_clinical_catalog_items')
                ->nullOnDelete();

            $table->index('service_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_request_items');
    }
};

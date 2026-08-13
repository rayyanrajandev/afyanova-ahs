<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pharmacy_order_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pharmacy_order_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['pharmacy_order_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('pharmacy_order_id')
                ->references('id')
                ->on('pharmacy_orders')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pharmacy_order_audit_logs');
    }
};

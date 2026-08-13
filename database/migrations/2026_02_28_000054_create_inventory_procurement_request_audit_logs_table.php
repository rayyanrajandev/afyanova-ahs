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
        Schema::create('inventory_procurement_request_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inventory_procurement_request_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['inventory_procurement_request_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('inventory_procurement_request_id')
                ->references('id')
                ->on('inventory_procurement_requests')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_procurement_request_audit_logs');
    }
};

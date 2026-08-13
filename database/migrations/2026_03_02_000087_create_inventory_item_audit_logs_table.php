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
        Schema::create('inventory_item_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inventory_item_id');
            $table->string('action', 120);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at');

            $table->index(['inventory_item_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['actor_id', 'created_at']);

            $table->foreign('inventory_item_id')
                ->references('id')
                ->on('inventory_items')
                ->cascadeOnDelete();

            $table->foreign('actor_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_item_audit_logs');
    }
};


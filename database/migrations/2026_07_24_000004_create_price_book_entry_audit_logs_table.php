<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_book_entry_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('price_book_entry_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['price_book_entry_id', 'created_at'], 'price_book_entry_audit_logs_item_created_at_idx');
            $table->index(['action', 'created_at'], 'price_book_entry_audit_logs_action_created_at_idx');

            $table->foreign('price_book_entry_id')
                ->references('id')
                ->on('price_book_entries')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_book_entry_audit_logs');
    }
};

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
        Schema::create('billing_invoice_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('billing_invoice_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['billing_invoice_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('billing_invoice_id')
                ->references('id')
                ->on('billing_invoices')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_invoice_audit_logs');
    }
};

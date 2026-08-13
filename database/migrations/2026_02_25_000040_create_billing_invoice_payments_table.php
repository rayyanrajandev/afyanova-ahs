<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoice_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('billing_invoice_id');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('payment_at');
            $table->decimal('amount', 14, 2);
            $table->decimal('cumulative_paid_amount', 14, 2);
            $table->string('payer_type', 30)->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('source_action', 50)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['billing_invoice_id', 'payment_at']);
            $table->index(['payment_at']);

            $table->foreign('billing_invoice_id')
                ->references('id')
                ->on('billing_invoices')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoice_payments');
    }
};


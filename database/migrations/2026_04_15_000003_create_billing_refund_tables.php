<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('billing_invoice_id')->index();
            $table->uuid('billing_invoice_payment_id')->nullable()->index();
            $table->uuid('patient_id')->index();
            $table->enum('refund_reason', ['overpayment', 'service_cancelled', 'insurance_adjustment', 'error'])->default('overpayment');
            $table->decimal('refund_amount', 15, 2);
            $table->enum('refund_method', ['cash', 'check', 'mobile_money', 'credit_note'])->default('cash');
            $table->string('mobile_money_provider')->nullable();
            $table->string('mobile_money_reference')->nullable();
            $table->string('card_reference')->nullable();
            $table->string('check_number')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->enum('refund_status', ['pending', 'approved', 'rejected', 'processing', 'processed', 'cancelled'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('billing_invoice_id')
                ->references('id')
                ->on('billing_invoices')
                ->onDelete('restrict');

            $table->foreign('billing_invoice_payment_id')
                ->references('id')
                ->on('billing_invoice_payments')
                ->onDelete('set null');

            $table->index(['refund_status', 'requested_at']);
        });

        Schema::create('billing_refund_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('billing_refund_id')->index();
            $table->enum('action', ['created', 'approved', 'rejected', 'processing', 'processed', 'cancelled']);
            $table->uuid('actor_id');
            $table->string('actor_name');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('billing_refund_id')
                ->references('id')
                ->on('billing_refunds')
                ->onDelete('cascade');

            $table->index(['billing_refund_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_refund_audit_logs');
        Schema::dropIfExists('billing_refunds');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payment_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('patient_id')->index();
            $table->uuid('billing_invoice_id')->nullable()->index();
            $table->uuid('cash_billing_account_id')->nullable()->index();
            $table->string('plan_number')->unique();
            $table->string('plan_name');
            $table->string('currency_code', 3)->default('TZS');
            $table->decimal('total_amount', 15, 2);
            $table->decimal('down_payment_amount', 15, 2)->default(0);
            $table->decimal('financed_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('balance_amount', 15, 2)->default(0);
            $table->unsignedInteger('installment_count')->default(1);
            $table->enum('installment_frequency', ['weekly', 'biweekly', 'monthly', 'quarterly', 'custom'])->default('monthly');
            $table->unsignedInteger('installment_interval_days')->nullable();
            $table->date('first_due_date');
            $table->date('next_due_date')->nullable();
            $table->timestamp('last_payment_at')->nullable();
            $table->enum('status', ['draft', 'active', 'partially_paid', 'completed', 'defaulted', 'cancelled'])->default('active');
            $table->text('terms_and_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('billing_invoice_id')->references('id')->on('billing_invoices')->nullOnDelete();
            $table->foreign('cash_billing_account_id')->references('id')->on('cash_billing_accounts')->nullOnDelete();
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['patient_id', 'status']);
        });

        Schema::create('billing_payment_plan_installments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('billing_payment_plan_id')->index();
            $table->unsignedInteger('installment_number');
            $table->date('due_date');
            $table->decimal('scheduled_amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('outstanding_amount', 15, 2)->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->enum('status', ['pending', 'partially_paid', 'paid', 'overdue', 'cancelled'])->default('pending');
            $table->uuid('source_billing_invoice_payment_id')->nullable()->index();
            $table->uuid('source_cash_billing_payment_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('billing_payment_plan_id')->references('id')->on('billing_payment_plans')->cascadeOnDelete();
            $table->foreign('source_billing_invoice_payment_id')->references('id')->on('billing_invoice_payments')->nullOnDelete();
            $table->foreign('source_cash_billing_payment_id')->references('id')->on('cash_billing_payments')->nullOnDelete();
            $table->unique(['billing_payment_plan_id', 'installment_number'], 'billing_payment_plan_installments_plan_number_unique');
            $table->index(['billing_payment_plan_id', 'status', 'due_date'], 'billing_payment_plan_installments_plan_status_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payment_plan_installments');
        Schema::dropIfExists('billing_payment_plans');
    }
};

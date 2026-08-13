<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_corporate_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('billing_payer_contract_id')->index();
            $table->string('account_code')->unique();
            $table->string('account_name');
            $table->string('billing_contact_name')->nullable();
            $table->string('billing_contact_email')->nullable();
            $table->string('billing_contact_phone')->nullable();
            $table->unsignedInteger('billing_cycle_day')->default(1);
            $table->unsignedInteger('settlement_terms_days')->default(30);
            $table->enum('status', ['active', 'inactive', 'suspended', 'closed'])->default('active');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('billing_payer_contract_id')->references('id')->on('billing_payer_contracts')->cascadeOnDelete();
            $table->index(['tenant_id', 'facility_id', 'status']);
        });

        Schema::create('billing_corporate_invoice_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('billing_corporate_account_id');
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->string('run_number')->unique();
            $table->date('billing_period_start');
            $table->date('billing_period_end');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('currency_code', 3)->default('TZS');
            $table->unsignedInteger('invoice_count')->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('balance_amount', 15, 2)->default(0);
            $table->timestamp('last_payment_at')->nullable();
            $table->enum('status', ['draft', 'issued', 'partially_paid', 'paid', 'closed', 'cancelled'])->default('draft');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('billing_corporate_account_id')->references('id')->on('billing_corporate_accounts')->cascadeOnDelete();
            $table->index(['billing_corporate_account_id', 'status'], 'billing_corp_runs_account_status_idx');
        });

        Schema::create('billing_corporate_run_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('billing_corporate_invoice_run_id');
            $table->uuid('billing_invoice_id');
            $table->uuid('patient_id')->nullable()->index();
            $table->string('invoice_number');
            $table->string('patient_display_name')->nullable();
            $table->date('invoice_date')->nullable();
            $table->decimal('invoice_total_amount', 15, 2)->default(0);
            $table->decimal('included_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('outstanding_amount', 15, 2)->default(0);
            $table->enum('status', ['open', 'partially_paid', 'paid', 'cancelled'])->default('open');
            $table->timestamps();

            $table->foreign('billing_corporate_invoice_run_id')->references('id')->on('billing_corporate_invoice_runs')->cascadeOnDelete();
            $table->foreign('billing_invoice_id')->references('id')->on('billing_invoices')->cascadeOnDelete();
            $table->index('billing_corporate_invoice_run_id', 'billing_corp_run_invoices_run_idx');
            $table->index('billing_invoice_id', 'billing_corp_run_invoices_invoice_idx');
            $table->unique(['billing_corporate_invoice_run_id', 'billing_invoice_id'], 'billing_corporate_run_invoices_run_invoice_unique');
        });

        Schema::create('billing_corporate_run_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('billing_corporate_invoice_run_id');
            $table->decimal('amount', 15, 2);
            $table->string('currency_code', 3)->default('TZS');
            $table->string('payment_method', 50);
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('allocations')->nullable();
            $table->timestamps();

            $table->foreign('billing_corporate_invoice_run_id')->references('id')->on('billing_corporate_invoice_runs')->cascadeOnDelete();
            $table->index('billing_corporate_invoice_run_id', 'billing_corp_run_payments_run_idx');
            $table->index(['billing_corporate_invoice_run_id', 'paid_at'], 'billing_corp_run_payments_paid_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_corporate_run_payments');
        Schema::dropIfExists('billing_corporate_run_invoices');
        Schema::dropIfExists('billing_corporate_invoice_runs');
        Schema::dropIfExists('billing_corporate_accounts');
    }
};

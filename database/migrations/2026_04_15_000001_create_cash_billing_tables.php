<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_billing_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('patient_id')->index();
            $table->string('currency_code')->default('TZS');
            $table->decimal('account_balance', 15, 2)->default(0);
            $table->decimal('total_charged', 15, 2)->default(0);
            $table->decimal('total_paid', 15, 2)->default(0);
            $table->enum('status', ['active', 'settled', 'suspended'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id']);
            $table->index(['tenant_id', 'patient_id']);
        });

        Schema::create('cash_billing_charges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cash_billing_account_id')->index();
            $table->uuid('service_id')->nullable();
            $table->string('service_name');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('charge_amount', 15, 2);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('charge_date');
            $table->uuid('reference_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('cash_billing_account_id')
                ->references('id')
                ->on('cash_billing_accounts')
                ->onDelete('cascade');

            $table->index(['cash_billing_account_id', 'charge_date']);
        });

        Schema::create('cash_billing_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cash_billing_account_id')->index();
            $table->decimal('amount_paid', 15, 2);
            $table->string('currency_code')->default('TZS');
            $table->enum('payment_method', ['cash', 'card', 'mobile_money', 'check'])->default('cash');
            $table->string('payment_reference')->nullable();
            $table->string('mobile_money_provider')->nullable();
            $table->string('mobile_money_transaction_id')->nullable();
            $table->string('card_last_four')->nullable();
            $table->string('check_number')->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('receipt_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('cash_billing_account_id')
                ->references('id')
                ->on('cash_billing_accounts')
                ->onDelete('cascade');

            $table->index(['cash_billing_account_id', 'paid_at']);
            $table->unique('receipt_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_billing_payments');
        Schema::dropIfExists('cash_billing_charges');
        Schema::dropIfExists('cash_billing_accounts');
    }
};

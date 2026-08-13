<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('reference_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->string('account_code');
            $table->string('account_name');
            $table->decimal('debit_amount', 15, 2)->nullable();
            $table->decimal('credit_amount', 15, 2)->nullable();
            $table->timestamp('entry_date');
            $table->timestamp('posting_date')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['draft', 'posted', 'reversed'])->default('draft');
            $table->uuid('batch_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id']);
            $table->index(['reference_id', 'reference_type']);
            $table->index(['account_code', 'entry_date']);
            $table->index(['status', 'posting_date']);
            $table->index(['batch_id']);
        });

        Schema::create('revenue_recognition_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('billing_invoice_id')->unique()->index();
            $table->timestamp('recognition_date');
            $table->string('recognition_method');
            $table->decimal('amount_recognized', 15, 2);
            $table->decimal('amount_adjusted', 15, 2)->default(0);
            $table->decimal('net_revenue', 15, 2);
            $table->json('gl_entry_ids')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('billing_invoice_id')
                ->references('id')
                ->on('billing_invoices')
                ->onDelete('cascade');

            $table->index(['recognition_date']);
        });

        Schema::create('patient_insurance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('patient_id')->index();
            $table->enum('insurance_type', ['private', 'nhif', 'other', 'none'])->default('none');
            $table->string('insurance_provider')->nullable();
            $table->string('policy_number')->nullable();
            $table->string('member_id')->nullable();
            $table->timestamp('effective_date')->nullable();
            $table->timestamp('expiry_date')->nullable();
            $table->string('coverage_level')->nullable();
            $table->enum('status', ['active', 'inactive', 'expired', 'cancelled'])->default('active');
            $table->timestamp('verification_date')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'status']);
            $table->index(['insurance_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_insurance_records');
        Schema::dropIfExists('revenue_recognition_records');
        Schema::dropIfExists('gl_journal_entries');
    }
};

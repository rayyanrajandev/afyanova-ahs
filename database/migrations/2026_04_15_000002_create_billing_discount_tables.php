<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_discount_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('discount_type', ['percentage', 'fixed', 'full_waiver', 'tiered'])->default('percentage');
            $table->decimal('discount_value', 15, 2)->nullable();
            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->json('applicable_services')->nullable();
            $table->boolean('auto_apply')->default(false);
            $table->decimal('requires_approval_above_amount', 15, 2)->nullable();
            $table->timestamp('active_from_date')->nullable();
            $table->timestamp('active_to_date')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id']);
            $table->index(['code', 'status']);
        });

        Schema::create('billing_discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('billing_invoice_id')->index();
            $table->uuid('billing_discount_policy_id')->index();
            $table->decimal('original_amount', 15, 2);
            $table->decimal('discount_amount', 15, 2);
            $table->decimal('final_amount', 15, 2);
            $table->uuid('applied_by_user_id');
            $table->timestamp('applied_at');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('billing_invoice_id')
                ->references('id')
                ->on('billing_invoices')
                ->onDelete('cascade');

            $table->foreign('billing_discount_policy_id')
                ->references('id')
                ->on('billing_discount_policies')
                ->onDelete('restrict');

            $table->index(['billing_invoice_id', 'applied_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_discounts');
        Schema::dropIfExists('billing_discount_policies');
    }
};

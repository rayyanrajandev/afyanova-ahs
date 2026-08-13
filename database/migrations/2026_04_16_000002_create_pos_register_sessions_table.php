<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_register_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('facility_id')->nullable()->index();
            $table->uuid('pos_register_id');
            $table->string('session_number', 40)->unique();
            $table->string('status', 20)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash_amount', 15, 2)->default(0);
            $table->decimal('closing_cash_amount', 15, 2)->nullable();
            $table->decimal('expected_cash_amount', 15, 2)->default(0);
            $table->decimal('discrepancy_amount', 15, 2)->nullable();
            $table->decimal('gross_sales_amount', 15, 2)->default(0);
            $table->decimal('total_discount_amount', 15, 2)->default(0);
            $table->decimal('total_tax_amount', 15, 2)->default(0);
            $table->decimal('cash_net_sales_amount', 15, 2)->default(0);
            $table->decimal('non_cash_sales_amount', 15, 2)->default(0);
            $table->unsignedInteger('sale_count')->default(0);
            $table->text('opening_note')->nullable();
            $table->text('closing_note')->nullable();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('pos_register_id')
                ->references('id')
                ->on('pos_registers')
                ->onDelete('cascade');

            $table->index(['pos_register_id', 'status']);
            $table->index(['opened_at', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_register_sessions');
    }
};

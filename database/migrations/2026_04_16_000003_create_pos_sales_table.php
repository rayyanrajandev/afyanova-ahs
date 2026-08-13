<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('facility_id')->nullable()->index();
            $table->uuid('pos_register_id');
            $table->uuid('pos_register_session_id');
            $table->uuid('patient_id')->nullable()->index();
            $table->string('sale_number', 40)->unique();
            $table->string('receipt_number', 40)->unique();
            $table->string('sale_channel', 40)->default('general_retail');
            $table->string('customer_type', 30)->default('anonymous');
            $table->string('customer_name')->nullable();
            $table->string('customer_reference')->nullable();
            $table->char('currency_code', 3);
            $table->string('status', 20)->default('completed');
            $table->decimal('subtotal_amount', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('balance_amount', 15, 2)->default(0);
            $table->decimal('change_amount', 15, 2)->default(0);
            $table->timestamp('sold_at');
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('pos_register_id')
                ->references('id')
                ->on('pos_registers')
                ->onDelete('cascade');

            $table->foreign('pos_register_session_id')
                ->references('id')
                ->on('pos_register_sessions')
                ->onDelete('cascade');

            $table->index(['pos_register_id', 'sold_at']);
            $table->index(['pos_register_session_id', 'sold_at']);
            $table->index(['status', 'sold_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sales');
    }
};

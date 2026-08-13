<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sale_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('facility_id')->nullable()->index();
            $table->uuid('pos_sale_id')->index();
            $table->uuid('pos_register_id')->index();
            $table->uuid('pos_register_session_id')->index();
            $table->string('adjustment_number', 40)->unique();
            $table->string('adjustment_type', 20);
            $table->decimal('amount', 12, 2);
            $table->decimal('cash_amount', 12, 2)->default(0);
            $table->decimal('non_cash_amount', 12, 2)->default(0);
            $table->string('currency_code', 3);
            $table->string('payment_method', 40)->nullable();
            $table->string('adjustment_reference', 120)->nullable();
            $table->string('reason_code', 60);
            $table->text('notes')->nullable();
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('pos_sale_id')
                ->references('id')
                ->on('pos_sales')
                ->onDelete('restrict');

            $table->foreign('pos_register_id')
                ->references('id')
                ->on('pos_registers')
                ->onDelete('restrict');

            $table->foreign('pos_register_session_id')
                ->references('id')
                ->on('pos_register_sessions')
                ->onDelete('restrict');

            $table->index(['pos_sale_id', 'processed_at'], 'pos_sale_adjustments_sale_processed_index');
            $table->index(['pos_register_session_id', 'adjustment_type'], 'pos_sale_adjustments_session_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_adjustments');
    }
};

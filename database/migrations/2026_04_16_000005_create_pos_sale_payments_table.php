<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sale_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pos_sale_id');
            $table->string('payment_method', 40);
            $table->decimal('amount_received', 15, 2);
            $table->decimal('amount_applied', 15, 2);
            $table->decimal('change_given', 15, 2)->default(0);
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('collected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('pos_sale_id')
                ->references('id')
                ->on('pos_sales')
                ->onDelete('cascade');

            $table->index(['pos_sale_id', 'paid_at']);
            $table->index(['payment_method', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_payments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sale_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pos_sale_id');
            $table->unsignedInteger('line_number');
            $table->string('item_type', 40)->default('manual');
            $table->string('item_reference')->nullable();
            $table->string('item_code')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_subtotal_amount', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total_amount', 15, 2);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('pos_sale_id')
                ->references('id')
                ->on('pos_sales')
                ->onDelete('cascade');

            $table->index(['pos_sale_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_lines');
    }
};

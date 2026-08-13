<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_write_offs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('billing_invoice_id');
            $table->uuid('patient_id');
            $table->decimal('amount', 14, 2);
            $table->text('reason');
            $table->string('status', 30)->default('pending');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('billing_invoice_id');
            $table->index('patient_id');
            $table->index('status');

            $table->foreign('billing_invoice_id')
                ->references('id')
                ->on('billing_invoices')
                ->cascadeOnDelete();

            $table->foreign('patient_id')
                ->references('id')
                ->on('patients')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_write_offs');
    }
};

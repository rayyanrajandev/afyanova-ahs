<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_daily_closes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->timestamp('opened_at');
            $table->decimal('total_cash_amount', 14, 2)->default(0);
            $table->decimal('total_card_amount', 14, 2)->default(0);
            $table->decimal('total_mpesa_amount', 14, 2)->default(0);
            $table->decimal('total_other_amount', 14, 2)->default(0);
            $table->decimal('total_revenue', 14, 2)->default(0);
            $table->decimal('total_refunds', 14, 2)->default(0);
            $table->decimal('net_revenue', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id']);
            $table->index('closed_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_daily_closes');
    }
};

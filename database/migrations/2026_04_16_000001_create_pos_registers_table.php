<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_registers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('facility_id')->nullable()->index();
            $table->string('register_code', 40);
            $table->string('register_name', 120);
            $table->string('location')->nullable();
            $table->char('default_currency_code', 3);
            $table->string('status', 20)->default('active');
            $table->string('status_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'register_name']);
            $table->unique(['tenant_id', 'facility_id', 'register_code'], 'pos_registers_scope_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_registers');
    }
};

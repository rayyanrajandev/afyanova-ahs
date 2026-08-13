<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('feature_flag_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('flag_name', 150);
            $table->string('scope_type', 20);
            $table->string('scope_key', 100);
            $table->boolean('enabled');
            $table->string('reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['flag_name', 'scope_type', 'scope_key']);
            $table->index(['scope_type', 'scope_key']);
            $table->index('flag_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feature_flag_overrides');
    }
};

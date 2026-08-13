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
        Schema::create('consultation_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('billing_service_catalog_item_id')
                  ->constrained('billing_service_catalog_items')
                  ->onDelete('cascade');
            $table->string('clinician_tier');
            $table->string('department');
            $table->unique(['clinician_tier', 'department']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultation_mappings');
    }
};

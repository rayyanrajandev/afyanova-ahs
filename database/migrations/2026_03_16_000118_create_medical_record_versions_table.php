<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_record_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('medical_record_id');
            $table->unsignedInteger('version_number');
            $table->json('snapshot');
            $table->json('changed_fields')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(['medical_record_id', 'version_number'], 'medical_record_versions_record_version_unique');
            $table->index(['medical_record_id', 'created_at'], 'medical_record_versions_record_created_at_index');

            $table->foreign('medical_record_id')
                ->references('id')
                ->on('medical_records')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_record_versions');
    }
};

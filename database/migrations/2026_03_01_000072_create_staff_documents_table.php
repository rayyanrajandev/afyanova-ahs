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
        Schema::create('staff_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('staff_profile_id');
            $table->uuid('tenant_id')->nullable();
            $table->string('document_type', 60);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('file_path', 500);
            $table->string('original_filename', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('checksum_sha256', 64);
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('verification_status', 20)->default('pending');
            $table->string('verification_reason', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('status_reason', 255)->nullable();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['staff_profile_id', 'created_at']);
            $table->index(['staff_profile_id', 'status']);
            $table->index(['staff_profile_id', 'verification_status']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['document_type', 'created_at']);
            $table->index(['expires_at', 'status']);

            $table->foreign('staff_profile_id')
                ->references('id')
                ->on('staff_profiles')
                ->cascadeOnDelete();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('uploaded_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('verified_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_documents');
    }
};


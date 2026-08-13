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
        Schema::create('platform_user_approval_case_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('approval_case_id');
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->text('comment_text');
            $table->timestamps();

            $table->index(['approval_case_id', 'created_at']);
            $table->index(['author_user_id', 'created_at']);

            $table->foreign('approval_case_id')
                ->references('id')
                ->on('platform_user_approval_cases')
                ->cascadeOnDelete();

            $table->foreign('author_user_id')
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
        Schema::dropIfExists('platform_user_approval_case_comments');
    }
};


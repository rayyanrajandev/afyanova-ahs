<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table): void {
            $table->timestamp('verified_at')->nullable()->after('resulted_at');
            $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->text('verification_note')->nullable()->after('verified_by_user_id');
            $table->index('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table): void {
            $table->dropIndex(['verified_at']);
            $table->dropConstrainedForeignId('verified_by_user_id');
            $table->dropColumn(['verified_at', 'verification_note']);
        });
    }
};


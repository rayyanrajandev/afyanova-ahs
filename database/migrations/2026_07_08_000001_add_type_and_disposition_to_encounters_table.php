<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->string('type', 20)->nullable()->after('status');
            $table->string('disposition', 40)->nullable()->after('status_reason');
            $table->text('disposition_notes')->nullable()->after('disposition');

            $table->index(['type', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropIndex(['type', 'opened_at']);
            $table->dropColumn(['type', 'disposition', 'disposition_notes']);
        });
    }
};

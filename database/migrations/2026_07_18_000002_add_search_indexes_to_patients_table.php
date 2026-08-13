<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports the Phase 1 Patient Directory filters: registration-window
 * (created_at), and exact/prefix lookups on national_id/first_name that
 * the existing (last_name, first_name) composite index doesn't serve on
 * their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->index('national_id');
            $table->index('first_name');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropIndex(['national_id']);
            $table->dropIndex(['first_name']);
            $table->dropIndex(['created_at']);
        });
    }
};

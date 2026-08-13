<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_book_entries', function (Blueprint $table): void {
            $table->uuid('supersedes_price_book_entry_id')->nullable()->after('status_reason');

            $table->foreign('supersedes_price_book_entry_id', 'price_book_entries_supersedes_fk')
                ->references('id')
                ->on('price_book_entries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('price_book_entries', function (Blueprint $table): void {
            $table->dropForeign('price_book_entries_supersedes_fk');
            $table->dropColumn('supersedes_price_book_entry_id');
        });
    }
};

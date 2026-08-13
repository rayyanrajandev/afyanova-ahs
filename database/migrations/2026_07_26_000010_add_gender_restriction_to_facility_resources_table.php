<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_resources', function (Blueprint $table): void {
            $table->string('gender_restriction', 10)->nullable()->after('bed_number');
        });
    }

    public function down(): void
    {
        Schema::table('facility_resources', function (Blueprint $table): void {
            $table->dropColumn('gender_restriction');
        });
    }
};

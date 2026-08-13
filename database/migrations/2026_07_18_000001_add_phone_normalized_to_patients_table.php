<?php

use App\Modules\Patient\Domain\ValueObjects\PatientPhoneNumber;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->string('phone_normalized', 20)->nullable()->after('phone')->index();
        });

        // Backfill existing rows — chunked to stay memory-safe on large tables.
        PatientModel::query()->whereNotNull('phone')->chunkById(500, function ($patients): void {
            foreach ($patients as $patient) {
                $normalized = PatientPhoneNumber::normalize($patient->phone);
                if ($normalized !== '') {
                    $patient->newQuery()->whereKey($patient->getKey())->update(['phone_normalized' => $normalized]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn('phone_normalized');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            if (! Schema::hasColumn('departments', 'is_patient_facing')) {
                $table->boolean('is_patient_facing')->default(false)->after('service_type');
            }

            if (! Schema::hasColumn('departments', 'is_appointmentable')) {
                $table->boolean('is_appointmentable')->default(false)->after('is_patient_facing');
            }
        });

        $patientFacingCodes = [
            'OPD', 'ANC', 'PED', 'DENT', 'EMR', 'SURG', 'THR', 'REC', 'MPR', 'DRS',
            'WARD', 'MAT', 'LAB', 'RAD', 'PHA', 'MRO', 'FIN', 'FDS',
        ];

        $appointmentableCodes = [
            'OPD', 'ANC', 'PED', 'DENT', 'SURG', 'MPR', 'DRS', 'LAB', 'RAD',
        ];

        DB::table('departments')
            ->select(['id', 'code', 'service_type'])
            ->orderBy('id')
            ->get()
            ->each(function (object $department) use ($patientFacingCodes, $appointmentableCodes): void {
                $code = strtoupper(trim((string) ($department->code ?? '')));
                $serviceType = strtolower(trim((string) ($department->service_type ?? '')));

                $isPatientFacing = in_array($code, $patientFacingCodes, true)
                    || in_array($serviceType, ['clinical', 'diagnostic', 'pharmacy'], true);
                $isAppointmentable = in_array($code, $appointmentableCodes, true);

                if ($isAppointmentable) {
                    $isPatientFacing = true;
                }

                DB::table('departments')
                    ->where('id', $department->id)
                    ->update([
                        'is_patient_facing' => $isPatientFacing,
                        'is_appointmentable' => $isAppointmentable,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            if (Schema::hasColumn('departments', 'is_appointmentable')) {
                $table->dropColumn('is_appointmentable');
            }

            if (Schema::hasColumn('departments', 'is_patient_facing')) {
                $table->dropColumn('is_patient_facing');
            }
        });
    }
};
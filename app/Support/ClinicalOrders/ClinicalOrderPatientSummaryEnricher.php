<?php

namespace App\Support\ClinicalOrders;

use App\Modules\Patient\Infrastructure\Models\PatientModel;

final class ClinicalOrderPatientSummaryEnricher
{
    /**
     * @param  list<array<string, mixed>>  $orders
     * @return array<string, array<string, mixed>>
     */
    public static function summariesByPatientId(array $orders): array
    {
        $patientIds = [];

        foreach ($orders as $order) {
            $patientId = trim((string) ($order['patient_id'] ?? ''));
            if ($patientId !== '') {
                $patientIds[$patientId] = true;
            }
        }

        if ($patientIds === []) {
            return [];
        }

        $patients = PatientModel::query()
            ->whereIn('id', array_keys($patientIds))
            ->get(['id', 'patient_number', 'first_name', 'middle_name', 'last_name', 'phone']);

        $summaries = [];

        foreach ($patients as $patient) {
            $summaries[(string) $patient->id] = self::transformPatient($patient);
        }

        return $summaries;
    }

    /**
     * @param  list<array<string, mixed>>  $rawOrders
     * @param  list<array<string, mixed>>  $transformedOrders
     * @return list<array<string, mixed>>
     */
    public static function attachToTransformedOrders(array $rawOrders, array $transformedOrders): array
    {
        $summaries = self::summariesByPatientId($rawOrders);

        return array_map(function (array $order) use ($summaries): array {
            $patientId = trim((string) ($order['patientId'] ?? ''));

            return array_merge($order, [
                'patient' => $patientId !== '' ? ($summaries[$patientId] ?? null) : null,
            ]);
        }, $transformedOrders);
    }

    /**
     * @return array<string, mixed>
     */
    private static function transformPatient(PatientModel $patient): array
    {
        return [
            'id' => (string) $patient->id,
            'patientNumber' => $patient->patient_number,
            'firstName' => $patient->first_name,
            'middleName' => $patient->middle_name,
            'lastName' => $patient->last_name,
            'phone' => $patient->phone,
        ];
    }
}

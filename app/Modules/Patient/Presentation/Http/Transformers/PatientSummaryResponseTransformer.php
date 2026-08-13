<?php

namespace App\Modules\Patient\Presentation\Http\Transformers;

use App\Modules\Billing\Presentation\Http\Transformers\PatientInsuranceRecordResponseTransformer;
use App\Modules\Encounter\Presentation\Http\Transformers\EncounterListItemResponseTransformer;

class PatientSummaryResponseTransformer
{
    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public static function transform(array $summary): array
    {
        $patient = $summary['patient'] ?? [];
        $insurance = $summary['insurance'] ?? null;
        $latestEncounter = $summary['latestEncounter'] ?? null;
        $workflowStatus = $summary['workflowStatus'] ?? null;
        $activeOrders = $summary['activeOrders'] ?? [];
        $upcomingAppointment = $summary['upcomingAppointment'] ?? null;
        $currentAdmission = $summary['currentAdmission'] ?? null;
        $stats = $summary['stats'] ?? [];
        $activeAppointment = $summary['activeAppointment'] ?? null;

        return [
            'patient' => [
                'id' => $patient['id'] ?? null,
                'patientNumber' => $patient['patient_number'] ?? null,
                'firstName' => $patient['first_name'] ?? null,
                'middleName' => $patient['middle_name'] ?? null,
                'lastName' => $patient['last_name'] ?? null,
                'gender' => $patient['gender'] ?? null,
                'dateOfBirth' => $patient['date_of_birth'] ?? null,
                'phone' => $patient['phone'] ?? null,
                'status' => $patient['status'] ?? null,
                'region' => $patient['region'] ?? null,
                'district' => $patient['district'] ?? null,
            ],
            'contact' => [
                'email' => $patient['email'] ?? null,
                'addressLine' => $patient['address_line'] ?? null,
                'nextOfKinName' => $patient['next_of_kin_name'] ?? null,
                'nextOfKinPhone' => $patient['next_of_kin_phone'] ?? null,
            ],
            'alerts' => array_map(
                [PatientAllergyResponseTransformer::class, 'transform'],
                $summary['allergies'] ?? [],
            ),
            'insurance' => $insurance !== null
                ? PatientInsuranceRecordResponseTransformer::transform($insurance)
                : null,
            'latestEncounter' => $latestEncounter !== null
                ? EncounterListItemResponseTransformer::transform($latestEncounter)
                : null,
            'workflowStatus' => $workflowStatus !== null ? [
                'step' => $workflowStatus['step'] ?? null,
                'department' => $workflowStatus['department'] ?? null,
                'appointmentId' => $workflowStatus['appointmentId'] ?? null,
                'serviceRequestId' => $workflowStatus['serviceRequestId'] ?? null,
            ] : null,
            'activeOrders' => [
                'labActive' => $activeOrders['labActive'] ?? 0,
                'pharmacyActive' => $activeOrders['pharmacyActive'] ?? 0,
                'imagingActive' => $activeOrders['imagingActive'] ?? 0,
                'procedureActive' => $activeOrders['procedureActive'] ?? 0,
                'clinicalProcedureActive' => $activeOrders['clinicalProcedureActive'] ?? 0,
            ],
            'upcomingAppointment' => $upcomingAppointment !== null ? [
                'id' => $upcomingAppointment['id'] ?? null,
                'appointmentNumber' => $upcomingAppointment['appointment_number'] ?? null,
                'department' => $upcomingAppointment['department'] ?? null,
                'scheduledAt' => $upcomingAppointment['scheduled_at'] ?? null,
                'reason' => $upcomingAppointment['reason'] ?? null,
            ] : null,
            'currentAdmission' => $currentAdmission !== null ? [
                'id' => $currentAdmission['id'] ?? null,
                'admissionNumber' => $currentAdmission['admission_number'] ?? null,
                'ward' => $currentAdmission['ward'] ?? null,
                'bed' => $currentAdmission['bed'] ?? null,
                'admittedAt' => $currentAdmission['admitted_at'] ?? null,
            ] : null,
            'stats' => [
                'totalVisits' => $stats['totalVisits'] ?? 0,
                'totalEncounters' => $stats['totalEncounters'] ?? 0,
                'outstandingInvoices' => $stats['outstandingInvoices'] ?? 0,
            ],
            'recentActivity' => array_map(
                static fn (array $entry): array => [
                    'type' => $entry['type'] ?? null,
                    'label' => $entry['label'] ?? null,
                    'occurredAt' => $entry['occurredAt'] !== null ? (string) $entry['occurredAt'] : null,
                ],
                $summary['recentActivity'] ?? [],
            ),
            'activeAppointment' => $activeAppointment !== null ? [
                'id' => $activeAppointment['id'] ?? null,
                'appointmentNumber' => $activeAppointment['appointment_number'] ?? null,
                'status' => $activeAppointment['status'] ?? null,
                'scheduledAt' => $activeAppointment['scheduled_at'] ?? null,
                'department' => $activeAppointment['department'] ?? null,
            ] : null,
        ];
    }
}

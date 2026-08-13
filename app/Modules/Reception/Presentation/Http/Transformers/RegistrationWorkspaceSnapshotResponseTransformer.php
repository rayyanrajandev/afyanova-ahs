<?php

namespace App\Modules\Reception\Presentation\Http\Transformers;

class RegistrationWorkspaceSnapshotResponseTransformer
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function transform(array $snapshot): array
    {
        $patient = is_array($snapshot['patient'] ?? null) ? $snapshot['patient'] : null;
        $visit = is_array($snapshot['visit'] ?? null) ? $snapshot['visit'] : null;
        $insurance = is_array($snapshot['insurance'] ?? null) ? $snapshot['insurance'] : null;
        $allergies = is_array($snapshot['allergies'] ?? null) ? $snapshot['allergies'] : [];
        $recentActivity = is_array($snapshot['recentActivity'] ?? null) ? $snapshot['recentActivity'] : [];

        return [
            'patient' => $patient !== null ? [
                'id' => $patient['id'] ?? null,
                'fullName' => self::fullName($patient),
                'sex' => $patient['gender'] ?? null,
                'ageYears' => $snapshot['patientAgeYears'] ?? null,
                'dateOfBirth' => $patient['date_of_birth'] ?? null,
                'phone' => $patient['phone'] ?? null,
                'mrn' => $patient['patient_number'] ?? null,
                'nationalId' => $patient['national_id'] ?? null,
                'email' => $patient['email'] ?? null,
                'address' => $patient['address_line'] ?? null,
                'nextOfKinName' => $patient['next_of_kin_name'] ?? null,
                'nextOfKinPhone' => $patient['next_of_kin_phone'] ?? null,
                'nextOfKinRelationship' => null,
            ] : null,
            'visit' => $visit !== null ? [
                'appointmentId' => $visit['id'] ?? null,
                'appointmentNumber' => $visit['appointment_number'] ?? null,
                'status' => $visit['status'] ?? null,
                'department' => $visit['department'] ?? null,
                'reason' => $visit['reason'] ?? null,
                'scheduledAt' => $visit['scheduled_at'] ?? null,
                'checkedInAt' => $visit['checked_in_at'] ?? null,
                'triagedAt' => $visit['triaged_at'] ?? null,
                'attendingClinicianUserId' => $visit['clinician_user_id'] ?? null,
            ] : null,
            'insurance' => $insurance !== null ? [
                'id' => $insurance['id'] ?? null,
                'provider' => $insurance['insurance_provider'] ?? null,
                'planName' => $insurance['plan_name'] ?? null,
                'policyNumber' => $insurance['policy_number'] ?? null,
                'memberId' => $insurance['member_id'] ?? null,
                'status' => $insurance['status'] ?? null,
                'verificationStatus' => $insurance['verification_status'] ?? null,
                'lastVerifiedAt' => $insurance['last_verified_at'] ?? null,
            ] : null,
            'allergies' => array_map(static fn (array $entry): array => [
                'id' => $entry['id'] ?? '',
                'substanceName' => $entry['substance_name'] ?? null,
                'reaction' => $entry['reaction'] ?? null,
                'severity' => $entry['severity'] ?? null,
            ], $allergies),
            'totalVisits' => (int) ($snapshot['totalVisits'] ?? 0),
            'outstandingInvoiceCount' => (int) ($snapshot['outstandingInvoices'] ?? 0),
            'firstRegisteredAt' => $snapshot['firstRegisteredAt'] ?? null,
            'lastVisitAt' => $snapshot['lastVisitAt'] ?? null,
            'recentActivity' => array_map(static fn (array $entry): array => [
                'type' => $entry['type'] ?? null,
                'label' => $entry['label'] ?? null,
                'occurredAt' => isset($entry['occurredAt']) ? (string) $entry['occurredAt'] : null,
            ], $recentActivity),
        ];
    }

    /**
     * @param  array<string, mixed>  $patient
     */
    private static function fullName(array $patient): string
    {
        $parts = [
            isset($patient['first_name']) ? trim((string) $patient['first_name']) : '',
            isset($patient['middle_name']) ? trim((string) $patient['middle_name']) : '',
            isset($patient['last_name']) ? trim((string) $patient['last_name']) : '',
        ];

        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

        return implode(' ', $parts);
    }
}

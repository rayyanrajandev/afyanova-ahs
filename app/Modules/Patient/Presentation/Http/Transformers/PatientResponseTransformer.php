<?php

namespace App\Modules\Patient\Presentation\Http\Transformers;

class PatientResponseTransformer
{
    public static function transform(array $patient): array
    {
        return [
            'id' => $patient['id'] ?? null,
            'patientNumber' => $patient['patient_number'] ?? null,
            'firstName' => $patient['first_name'] ?? null,
            'middleName' => $patient['middle_name'] ?? null,
            'lastName' => $patient['last_name'] ?? null,
            'gender' => $patient['gender'] ?? null,
            'dateOfBirth' => $patient['date_of_birth'] ?? null,
            'phone' => $patient['phone'] ?? null,
            'email' => $patient['email'] ?? null,
            'nationalId' => $patient['national_id'] ?? null,
            'countryCode' => $patient['country_code'] ?? null,
            'region' => $patient['region'] ?? null,
            'district' => $patient['district'] ?? null,
            'addressLine' => $patient['address_line'] ?? null,
            'nextOfKinName' => $patient['next_of_kin_name'] ?? null,
            'nextOfKinPhone' => $patient['next_of_kin_phone'] ?? null,
            'status' => $patient['status'] ?? null,
            'statusReason' => $patient['status_reason'] ?? null,
            'createdAt' => $patient['created_at'] ?? null,
            'updatedAt' => $patient['updated_at'] ?? null,
            'lastVisitAt' => $patient['last_visit_at'] ?? null,
            'careStatus' => $patient['care_status'] ?? null,
        ];
    }
}

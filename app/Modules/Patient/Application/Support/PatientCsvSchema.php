<?php

namespace App\Modules\Patient\Application\Support;

use App\Modules\Patient\Domain\ValueObjects\PatientStatus;
use Illuminate\Support\Str;

/**
 * Backup/restore CSV shape for the patient registry (dev/testing tool, not
 * a clerk-facing registration import): every column round-trips exactly,
 * including `id`, `patient_number`, `status`, and timestamps, so re-importing
 * a previously exported file reproduces the same records rather than
 * creating fresh ones — other modules (appointments, admissions, service
 * requests, etc.) hold foreign keys to `patients.id` that must survive a
 * restore intact.
 */
class PatientCsvSchema
{
    public const SCHEMA_VERSION = 'patients-csv.v1';

    /**
     * @var array<int, string>
     */
    public const COLUMNS = [
        'id',
        'tenant_id',
        'patient_number',
        'first_name',
        'middle_name',
        'last_name',
        'gender',
        'date_of_birth',
        'phone',
        'email',
        'national_id',
        'country_code',
        'region',
        'district',
        'address_line',
        'next_of_kin_name',
        'next_of_kin_phone',
        'status',
        'status_reason',
        'created_at',
        'updated_at',
    ];

    /**
     * @param array<string, mixed> $patient
     * @return array<string, string>
     */
    public static function toCsvRow(array $patient): array
    {
        $row = [];

        foreach (self::COLUMNS as $column) {
            $value = $patient[$column] ?? '';
            $row[$column] = $value === null ? '' : (string) $value;
        }

        return $row;
    }

    /**
     * @param array<string, string> $row
     * @return array{errors: array<string, string>, payload: array<string, mixed>}
     */
    public static function fromCsvRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $get = static fn (string $key): string => trim((string) ($row[$key] ?? ''));

        $id = $get('id');
        if ($id !== '' && ! Str::isUuid($id)) {
            $errors["rows.{$rowNumber}.id"] = 'id is not a valid UUID.';
        }

        $firstName = $get('first_name');
        if ($firstName === '') {
            $errors["rows.{$rowNumber}.first_name"] = 'first_name is required.';
        }

        $lastName = $get('last_name');
        if ($lastName === '') {
            $errors["rows.{$rowNumber}.last_name"] = 'last_name is required.';
        }

        $gender = strtolower($get('gender'));
        if (! in_array($gender, ['male', 'female', 'other', 'unknown'], true)) {
            $errors["rows.{$rowNumber}.gender"] = 'gender must be one of male, female, other, unknown.';
        }

        $dateOfBirth = $get('date_of_birth');
        if ($dateOfBirth === '' || ! self::isValidDate($dateOfBirth)) {
            $errors["rows.{$rowNumber}.date_of_birth"] = 'date_of_birth must be a valid date (YYYY-MM-DD).';
        }

        $phone = $get('phone');
        if ($phone === '') {
            $errors["rows.{$rowNumber}.phone"] = 'phone is required.';
        }

        $countryCode = strtoupper($get('country_code'));
        if (strlen($countryCode) !== 2) {
            $errors["rows.{$rowNumber}.country_code"] = 'country_code must be a 2-letter code.';
        }

        $region = $get('region');
        if ($region === '') {
            $errors["rows.{$rowNumber}.region"] = 'region is required.';
        }

        $district = $get('district');
        if ($district === '') {
            $errors["rows.{$rowNumber}.district"] = 'district is required.';
        }

        $addressLine = $get('address_line');
        if ($addressLine === '') {
            $errors["rows.{$rowNumber}.address_line"] = 'address_line is required.';
        }

        $status = strtolower($get('status'));
        if (! in_array($status, PatientStatus::values(), true)) {
            $status = PatientStatus::ACTIVE->value;
        }

        $payload = [
            'first_name' => $firstName,
            'middle_name' => self::nullableValue($get('middle_name')),
            'last_name' => $lastName,
            'gender' => $gender,
            'date_of_birth' => $dateOfBirth,
            'phone' => $phone,
            'email' => self::nullableValue($get('email')),
            'national_id' => self::nullableValue($get('national_id')),
            'country_code' => $countryCode,
            'region' => $region,
            'district' => $district,
            'address_line' => $addressLine,
            'next_of_kin_name' => self::nullableValue($get('next_of_kin_name')),
            'next_of_kin_phone' => self::nullableValue($get('next_of_kin_phone')),
            'status' => $status,
            'status_reason' => self::nullableValue($get('status_reason')),
        ];

        if ($id !== '') {
            $payload['id'] = $id;
        }

        $patientNumber = $get('patient_number');
        if ($patientNumber !== '') {
            $payload['patient_number'] = $patientNumber;
        }

        return ['errors' => $errors, 'payload' => $payload];
    }

    /**
     * @return array<string, string>
     */
    public static function exampleRow(): array
    {
        return [
            'id' => '',
            'tenant_id' => '',
            'patient_number' => '',
            'first_name' => 'Jane',
            'middle_name' => '',
            'last_name' => 'Doe',
            'gender' => 'female',
            'date_of_birth' => '1990-05-14',
            'phone' => '0712345678',
            'email' => 'jane.doe@example.com',
            'national_id' => '',
            'country_code' => 'TZ',
            'region' => 'Dar es Salaam',
            'district' => 'Kinondoni',
            'address_line' => 'Mikocheni Street 12',
            'next_of_kin_name' => 'John Doe',
            'next_of_kin_phone' => '0712345679',
            'status' => 'active',
            'status_reason' => '',
            'created_at' => '',
            'updated_at' => '',
        ];
    }

    private static function nullableValue(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private static function isValidDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}

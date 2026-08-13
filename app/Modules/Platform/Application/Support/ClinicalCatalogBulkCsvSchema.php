<?php

namespace App\Modules\Platform\Application\Support;

use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogItemStatus;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use InvalidArgumentException;

class ClinicalCatalogBulkCsvSchema
{
    public const SCHEMA_VERSION = 'clinical-catalog-items-csv.v1';

    public const MAX_IMPORT_ROWS = 200;

    public const MAX_BULK_STATUS_IDS = 100;

    /**
     * @return array<int, string>
     */
    public static function columnsForCatalogType(string $catalogType): array
    {
        return array_merge(self::commonColumns(), self::domainColumns($catalogType));
    }

    /**
     * @return array<int, string>
     */
    public static function commonColumns(): array
    {
        return [
            'code',
            'name',
            'category',
            'unit',
            'facility_tier',
            'department_code',
            'billing_service_code',
            'description',
            'status',
            'status_reason',
            'standard_local',
            'standard_loinc',
            'standard_snomed_ct',
            'standard_nhif',
            'standard_msd',
            'standard_cpt',
            'standard_icd',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function domainColumns(string $catalogType): array
    {
        return match ($catalogType) {
            ClinicalCatalogType::LAB_TEST->value => [
                'sample_type',
                'specimen_container',
                'turnaround_hours',
                'fasting_required',
            ],
            ClinicalCatalogType::RADIOLOGY_PROCEDURE->value => [
                'modality',
                'body_site',
                'contrast_required',
                'study_duration_minutes',
            ],
            ClinicalCatalogType::THEATRE_PROCEDURE->value => [
                'procedure_class',
                'anesthesia_type',
                'expected_duration_minutes',
                'sterile_prep_required',
            ],
            ClinicalCatalogType::CLINICAL_PROCEDURE->value => [
                'procedure_setting',
                'performer_role',
                'estimated_duration_minutes',
                'consent_required',
            ],
            ClinicalCatalogType::FORMULARY_ITEM->value => [
                'strength',
                'dosage_form',
                'route',
                'pack_size',
                'otc_allowed',
                'stock_unit',
                'conversion_factor',
                'purchase_unit',
                'purchase_unit_quantity',
            ],
            default => throw new InvalidArgumentException('Unsupported clinical catalog type for bulk CSV.'),
        };
    }

    /**
     * @return array<int, string>
     */
    public static function exampleRowForCatalogType(string $catalogType): array
    {
        $base = [
            'LAB-CBC-001',
            'Complete Blood Count',
            'hematology',
            'panel',
            'district_hospital',
            'LAB',
            'LAB-CBC-TARIFF-001',
            'Standard hematology profile.',
            'active',
            '',
            'LOCAL-CBC',
            '58410-2',
            '',
            '',
            '',
            '',
            '',
        ];

        return match ($catalogType) {
            ClinicalCatalogType::LAB_TEST->value => array_merge($base, ['blood', 'EDTA tube', '24', 'no']),
            ClinicalCatalogType::RADIOLOGY_PROCEDURE->value => array_merge([
                'RAD-US-ABD-001',
                'Abdominal Ultrasound',
                'ultrasound',
                'study',
                'district_hospital',
                'RAD',
                'RAD-US-ABD-TARIFF',
                'Routine abdominal ultrasound.',
                'active',
                '',
                'LOCAL-US-ABD',
                '',
                '',
                '',
                '',
                '',
                '',
            ], ['ultrasound', 'abdomen', 'no', '30']),
            ClinicalCatalogType::THEATRE_PROCEDURE->value => array_merge([
                'THR-APP-001',
                'Appendectomy',
                'general_surgery',
                'procedure',
                'regional_hospital',
                'THR',
                'THR-APP-TARIFF',
                'Major appendectomy procedure.',
                'active',
                '',
                'LOCAL-APP',
                '',
                '',
                '',
                '',
                '',
                '',
            ], ['major', 'general', '90', 'yes']),
            ClinicalCatalogType::CLINICAL_PROCEDURE->value => array_merge([
                'CLN-WOUND-001',
                'Wound dressing change',
                'nursing_procedure',
                'procedure',
                'health_centre',
                'NUR',
                'CLN-WOUND-TARIFF',
                'Routine wound assessment and dressing change.',
                'active',
                '',
                'LOCAL-WOUND-DRESS',
                '',
                '',
                '',
                '',
                '',
                '',
            ], ['outpatient', 'nurse', '20', 'no']),
            ClinicalCatalogType::FORMULARY_ITEM->value => array_merge([
                'MED-AMOX-500CAP',
                'Amoxicillin 500mg capsule',
                'antibiotics',
                'capsule',
                'dispensary',
                'PHM',
                'MED-AMOX-TARIFF',
                'First-line antibiotic.',
                'active',
                '',
                'LOCAL-AMOX-500',
                '',
                '',
                'NHIF-AMOX',
                '',
                '',
                '',
            ], ['500mg', 'capsule', 'oral', '21', 'no', 'bottle', '100', 'box', '10']),
            default => throw new InvalidArgumentException('Unsupported clinical catalog type for bulk CSV.'),
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, string>
     */
    public static function itemToCsvRow(array $item, ?string $departmentCode = null): array
    {
        $catalogType = (string) ($item['catalog_type'] ?? '');
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $codes = is_array($item['codes'] ?? null) ? $item['codes'] : [];

        $row = [
            'code' => (string) ($item['code'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
            'category' => (string) ($item['category'] ?? ''),
            'unit' => (string) ($item['unit'] ?? ''),
            'facility_tier' => (string) ($item['facility_tier'] ?? ''),
            'department_code' => $departmentCode ?? '',
            'billing_service_code' => self::billingServiceCodeFromMetadata($metadata),
            'description' => (string) ($item['description'] ?? ''),
            'status' => (string) ($item['status'] ?? ''),
            'status_reason' => (string) ($item['status_reason'] ?? ''),
            'standard_local' => (string) ($codes['LOCAL'] ?? ''),
            'standard_loinc' => (string) ($codes['LOINC'] ?? ''),
            'standard_snomed_ct' => (string) ($codes['SNOMED_CT'] ?? ''),
            'standard_nhif' => (string) ($codes['NHIF'] ?? ''),
            'standard_msd' => (string) ($codes['MSD'] ?? ''),
            'standard_cpt' => (string) ($codes['CPT'] ?? ''),
            'standard_icd' => (string) ($codes['ICD'] ?? ''),
        ];

        foreach (self::domainColumns($catalogType) as $column) {
            $row[$column] = self::domainColumnValue($catalogType, $column, $metadata);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{errors: array<string, string>, payload: array<string, mixed>}
     */
    public static function rowToPersistencePayload(string $catalogType, array $row, int $rowNumber): array
    {
        $errors = [];
        $code = self::normalizeCode((string) ($row['code'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));

        if ($code === '') {
            $errors['code'] = 'Code is required.';
        }

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }

        $status = strtolower(trim((string) ($row['status'] ?? 'active')));
        if ($status === '') {
            $status = ClinicalCatalogItemStatus::ACTIVE->value;
        } elseif (! in_array($status, ClinicalCatalogItemStatus::values(), true)) {
            $errors['status'] = 'Status must be active, inactive, or retired.';
        }

        $facilityTier = trim((string) ($row['facility_tier'] ?? ''));
        if ($facilityTier !== '' && ! in_array($facilityTier, self::facilityTierValues(), true)) {
            $errors['facility_tier'] = 'Facility tier is invalid.';
        }

        $metadata = self::buildMetadata($catalogType, $row, $errors);
        $codes = self::buildCodes($row);

        self::validateDomainFields($catalogType, $row, $errors);

        $payload = [
            'code' => $code,
            'name' => $name,
            'facility_tier' => $facilityTier !== '' ? $facilityTier : null,
            'department_code' => trim((string) ($row['department_code'] ?? '')),
            'category' => self::nullableTrimmed((string) ($row['category'] ?? '')),
            'unit' => self::nullableTrimmed((string) ($row['unit'] ?? '')),
            'billing_service_code' => self::nullableTrimmed((string) ($row['billing_service_code'] ?? '')),
            'description' => self::nullableTrimmed((string) ($row['description'] ?? '')),
            'metadata' => $metadata === [] ? null : $metadata,
            'codes' => $codes,
            'status' => $status,
            'status_reason' => self::nullableTrimmed((string) ($row['status_reason'] ?? '')),
        ];

        if ($errors !== []) {
            $formatted = [];
            foreach ($errors as $field => $message) {
                $formatted[sprintf('rows.%d.%s', $rowNumber, $field)] = $message;
            }

            return ['errors' => $formatted, 'payload' => $payload];
        }

        return ['errors' => [], 'payload' => $payload];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private static function buildMetadata(string $catalogType, array $row, array &$errors): array
    {
        $metadata = [];

        if ($catalogType === ClinicalCatalogType::LAB_TEST->value) {
            self::appendIfPresent($metadata, 'sampleType', (string) ($row['sample_type'] ?? ''));
            self::appendIfPresent($metadata, 'specimenContainer', (string) ($row['specimen_container'] ?? ''));
            self::appendIfPresent($metadata, 'turnaroundHours', (string) ($row['turnaround_hours'] ?? ''));
            $fasting = self::parseBoolean((string) ($row['fasting_required'] ?? ''));
            if ($fasting !== null) {
                $metadata['fastingRequired'] = $fasting;
            }
            self::assertPositiveWholeNumber($row['turnaround_hours'] ?? '', 'turnaround_hours', $errors);

            return $metadata;
        }

        if ($catalogType === ClinicalCatalogType::RADIOLOGY_PROCEDURE->value) {
            self::appendIfPresent($metadata, 'modality', (string) ($row['modality'] ?? ''));
            self::appendIfPresent($metadata, 'bodySite', (string) ($row['body_site'] ?? ''));
            self::appendIfPresent($metadata, 'studyDurationMinutes', (string) ($row['study_duration_minutes'] ?? ''));
            $contrast = self::parseBoolean((string) ($row['contrast_required'] ?? ''));
            if ($contrast !== null) {
                $metadata['contrastRequired'] = $contrast;
            }
            self::assertPositiveWholeNumber($row['study_duration_minutes'] ?? '', 'study_duration_minutes', $errors);

            return $metadata;
        }

        if ($catalogType === ClinicalCatalogType::THEATRE_PROCEDURE->value) {
            self::appendIfPresent($metadata, 'procedureClass', (string) ($row['procedure_class'] ?? ''));
            self::appendIfPresent($metadata, 'anesthesiaType', (string) ($row['anesthesia_type'] ?? ''));
            self::appendIfPresent($metadata, 'expectedDurationMinutes', (string) ($row['expected_duration_minutes'] ?? ''));
            $sterile = self::parseBoolean((string) ($row['sterile_prep_required'] ?? ''));
            if ($sterile !== null) {
                $metadata['sterilePrepRequired'] = $sterile;
            }
            self::assertPositiveWholeNumber($row['expected_duration_minutes'] ?? '', 'expected_duration_minutes', $errors);

            return $metadata;
        }

        if ($catalogType === ClinicalCatalogType::CLINICAL_PROCEDURE->value) {
            self::appendIfPresent($metadata, 'procedureSetting', (string) ($row['procedure_setting'] ?? ''));
            self::appendIfPresent($metadata, 'performerRole', (string) ($row['performer_role'] ?? ''));
            self::appendIfPresent($metadata, 'estimatedDurationMinutes', (string) ($row['estimated_duration_minutes'] ?? ''));
            $consent = self::parseBoolean((string) ($row['consent_required'] ?? ''));
            if ($consent !== null) {
                $metadata['consentRequired'] = $consent;
            }
            self::assertPositiveWholeNumber($row['estimated_duration_minutes'] ?? '', 'estimated_duration_minutes', $errors);

            return $metadata;
        }

        self::appendIfPresent($metadata, 'strength', (string) ($row['strength'] ?? ''));
        self::appendIfPresent($metadata, 'dosageForm', (string) ($row['dosage_form'] ?? ''));
        self::appendIfPresent($metadata, 'route', (string) ($row['route'] ?? ''));
        self::appendIfPresent($metadata, 'packSize', (string) ($row['pack_size'] ?? ''));
        $otc = self::parseBoolean((string) ($row['otc_allowed'] ?? ''));
        if ($otc !== null) {
            $metadata['otcAllowed'] = $otc;
        }
        self::appendIfPresent($metadata, 'stockUnit', (string) ($row['stock_unit'] ?? ''));
        self::appendIfPresent($metadata, 'conversionFactor', (string) ($row['conversion_factor'] ?? ''));
        self::appendIfPresent($metadata, 'purchaseUnit', (string) ($row['purchase_unit'] ?? ''));
        self::appendIfPresent($metadata, 'purchaseUnitQuantity', (string) ($row['purchase_unit_quantity'] ?? ''));

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>|null
     */
    private static function buildCodes(array $row): ?array
    {
        $codes = array_filter([
            'LOCAL' => self::nullableTrimmed((string) ($row['standard_local'] ?? '')),
            'LOINC' => self::nullableTrimmed((string) ($row['standard_loinc'] ?? '')),
            'SNOMED_CT' => self::nullableTrimmed((string) ($row['standard_snomed_ct'] ?? '')),
            'NHIF' => self::nullableTrimmed((string) ($row['standard_nhif'] ?? '')),
            'MSD' => self::nullableTrimmed((string) ($row['standard_msd'] ?? '')),
            'CPT' => self::nullableTrimmed((string) ($row['standard_cpt'] ?? '')),
            'ICD' => self::nullableTrimmed((string) ($row['standard_icd'] ?? '')),
        ], static fn (?string $value): bool => $value !== null);

        return $codes === [] ? null : $codes;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $errors
     */
    private static function validateDomainFields(string $catalogType, array $row, array &$errors): void
    {
        if ($catalogType === ClinicalCatalogType::LAB_TEST->value) {
            self::assertPositiveWholeNumber($row['turnaround_hours'] ?? '', 'turnaround_hours', $errors);
        }

        if ($catalogType === ClinicalCatalogType::RADIOLOGY_PROCEDURE->value) {
            self::assertPositiveWholeNumber($row['study_duration_minutes'] ?? '', 'study_duration_minutes', $errors);
        }

        if ($catalogType === ClinicalCatalogType::THEATRE_PROCEDURE->value) {
            self::assertPositiveWholeNumber($row['expected_duration_minutes'] ?? '', 'expected_duration_minutes', $errors);
        }

        if ($catalogType === ClinicalCatalogType::CLINICAL_PROCEDURE->value) {
            self::assertPositiveWholeNumber($row['estimated_duration_minutes'] ?? '', 'estimated_duration_minutes', $errors);
        }

        if ($catalogType === ClinicalCatalogType::FORMULARY_ITEM->value) {
            self::assertPositiveNumeric($row['conversion_factor'] ?? '', 'conversion_factor', $errors);
        }
    }

    private static function domainColumnValue(string $catalogType, string $column, array $metadata): string
    {
        return match ([$catalogType, $column]) {
            [ClinicalCatalogType::LAB_TEST->value, 'sample_type'] => self::metadataString($metadata, 'sampleType'),
            [ClinicalCatalogType::LAB_TEST->value, 'specimen_container'] => self::metadataString($metadata, 'specimenContainer'),
            [ClinicalCatalogType::LAB_TEST->value, 'turnaround_hours'] => self::metadataString($metadata, 'turnaroundHours'),
            [ClinicalCatalogType::LAB_TEST->value, 'fasting_required'] => self::booleanCsv(self::metadataBool($metadata, 'fastingRequired')),
            [ClinicalCatalogType::RADIOLOGY_PROCEDURE->value, 'modality'] => self::metadataString($metadata, 'modality'),
            [ClinicalCatalogType::RADIOLOGY_PROCEDURE->value, 'body_site'] => self::metadataString($metadata, 'bodySite'),
            [ClinicalCatalogType::RADIOLOGY_PROCEDURE->value, 'contrast_required'] => self::booleanCsv(self::metadataBool($metadata, 'contrastRequired')),
            [ClinicalCatalogType::RADIOLOGY_PROCEDURE->value, 'study_duration_minutes'] => self::metadataString($metadata, 'studyDurationMinutes'),
            [ClinicalCatalogType::THEATRE_PROCEDURE->value, 'procedure_class'] => self::metadataString($metadata, 'procedureClass'),
            [ClinicalCatalogType::THEATRE_PROCEDURE->value, 'anesthesia_type'] => self::metadataString($metadata, 'anesthesiaType'),
            [ClinicalCatalogType::THEATRE_PROCEDURE->value, 'expected_duration_minutes'] => self::metadataString($metadata, 'expectedDurationMinutes'),
            [ClinicalCatalogType::THEATRE_PROCEDURE->value, 'sterile_prep_required'] => self::booleanCsv(self::metadataBool($metadata, 'sterilePrepRequired')),
            [ClinicalCatalogType::CLINICAL_PROCEDURE->value, 'procedure_setting'] => self::metadataString($metadata, 'procedureSetting'),
            [ClinicalCatalogType::CLINICAL_PROCEDURE->value, 'performer_role'] => self::metadataString($metadata, 'performerRole'),
            [ClinicalCatalogType::CLINICAL_PROCEDURE->value, 'estimated_duration_minutes'] => self::metadataString($metadata, 'estimatedDurationMinutes'),
            [ClinicalCatalogType::CLINICAL_PROCEDURE->value, 'consent_required'] => self::booleanCsv(self::metadataBool($metadata, 'consentRequired')),
            [ClinicalCatalogType::FORMULARY_ITEM->value, 'strength'] => self::metadataString($metadata, 'strength'),
            [ClinicalCatalogType::FORMULARY_ITEM->value, 'dosage_form'] => self::metadataString($metadata, 'dosageForm'),
            [ClinicalCatalogType::FORMULARY_ITEM->value, 'route'] => self::metadataString($metadata, 'route'),
            [ClinicalCatalogType::FORMULARY_ITEM->value, 'pack_size'] => self::metadataString($metadata, 'packSize'),
            [ClinicalCatalogType::FORMULARY_ITEM->value, 'otc_allowed'] => self::booleanCsv(self::metadataBool($metadata, 'otcAllowed')),
            [ClinicalCatalogType::FORMULARY_ITEM->value, 'stock_unit'] => self::metadataString($metadata, 'stockUnit'),
            [ClinicalCatalogType::FORMULARY_ITEM->value, 'conversion_factor'] => self::metadataString($metadata, 'conversionFactor'),
            [ClinicalCatalogType::FORMULARY_ITEM->value, 'purchase_unit'] => self::metadataString($metadata, 'purchaseUnit'),
            [ClinicalCatalogType::FORMULARY_ITEM->value, 'purchase_unit_quantity'] => self::metadataString($metadata, 'purchaseUnitQuantity'),
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function billingServiceCodeFromMetadata(array $metadata): string
    {
        foreach (['billingServiceCode', 'billing_service_code'] as $key) {
            $value = strtoupper(trim((string) ($metadata[$key] ?? '')));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function metadataString(array $metadata, string $key): string
    {
        $value = $metadata[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function metadataBool(array $metadata, string $key): ?bool
    {
        $value = $metadata[$key] ?? null;
        if ($value === true || $value === false) {
            return $value;
        }

        return null;
    }

    private static function booleanCsv(?bool $value): string
    {
        if ($value === true) {
            return 'yes';
        }

        if ($value === false) {
            return 'no';
        }

        return '';
    }

    private static function parseBoolean(string $value): ?bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['yes', 'y', 'true', '1'], true)) {
            return true;
        }

        if (in_array($normalized, ['no', 'n', 'false', '0'], true)) {
            return false;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function appendIfPresent(array &$metadata, string $key, string $value): void
    {
        $normalized = trim($value);
        if ($normalized !== '') {
            $metadata[$key] = $normalized;
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    private static function assertPositiveWholeNumber(mixed $value, string $field, array &$errors): void
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return;
        }

        if (! preg_match('/^\d+$/', $normalized) || (int) $normalized <= 0) {
            $errors[$field] = sprintf('%s must be a whole number greater than 0.', str_replace('_', ' ', $field));
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    private static function assertPositiveNumeric(mixed $value, string $field, array &$errors): void
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return;
        }

        if (! is_numeric($normalized) || (float) $normalized <= 0) {
            $errors[$field] = sprintf('%s must be a positive number greater than 0.', str_replace('_', ' ', $field));
        }
    }

    private static function normalizeCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    private static function nullableTrimmed(string $value): ?string
    {
        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<int, string>
     */
    private static function facilityTierValues(): array
    {
        return [
            'dispensary',
            'health_centre',
            'district_hospital',
            'regional_hospital',
            'zonal_referral',
        ];
    }
}

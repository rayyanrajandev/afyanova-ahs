<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicalCatalogItemModel extends Model
{
    use HasUuids;

    protected $table = 'platform_clinical_catalog_items';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'facility_tier',
        'catalog_type',
        'code',
        'name',
        'generic_name',
        'dosage_form',
        'strength',
        'route',
        'container_volume_value',
        'container_volume_unit',
        'storage_conditions',
        'requires_cold_chain',
        'is_controlled_substance',
        'controlled_substance_schedule',
        'direct_service_eligible',
        'refillable_without_prescription',
        'generic_group_code',
        'department_id',
        'category',
        'unit',
        'description',
        'metadata',
        'codes',
        'status',
        'status_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'codes' => 'array',
            'requires_cold_chain' => 'boolean',
            'is_controlled_substance' => 'boolean',
            'direct_service_eligible' => 'boolean',
            'refillable_without_prescription' => 'boolean',
            'container_volume_value' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function packagingTemplates(): HasMany
    {
        return $this->hasMany(ClinicalCatalogItemPackagingTemplateModel::class, 'clinical_catalog_item_id');
    }

    /**
     * @return array{numeratorValue: int|float, numeratorUnit: string|null, denominatorValue: int|float, denominatorUnit: string|null}|null
     */
    public function parsedStrength(): ?array
    {
        $strength = $this->strength;
        if ($strength === null || $strength === '') {
            return null;
        }

        $strength = trim($strength);

        // Match patterns: "100 mg/2 ml", "500 mg", "250 mg/5 ml", "10 mg/1.5 ml"
        if (preg_match('/^([\d.]+)\s*([a-zA-Z°%]+)(?:\s*\/\s*([\d.]+)\s*([a-zA-Z°%]+))?$/', $strength, $m)) {
            $numValue = is_numeric($m[1]) ? (str_contains($m[1], '.') ? (float) $m[1] : (int) $m[1]) : 0;
            $numUnit = $m[2] !== '' ? $m[2] : null;

            if (isset($m[3], $m[4]) && $m[3] !== '' && $m[4] !== '') {
                $denValue = is_numeric($m[3]) ? (str_contains($m[3], '.') ? (float) $m[3] : (int) $m[3]) : 1;
                $denUnit = $m[4] !== '' ? $m[4] : null;
            } else {
                $denValue = 1;
                $denUnit = null;
            }

            return [
                'numeratorValue' => $numValue,
                'numeratorUnit' => $numUnit,
                'denominatorValue' => $denValue,
                'denominatorUnit' => $denUnit,
            ];
        }

        return null;
    }
}

<?php

namespace App\Modules\Billing\Infrastructure\Models;

use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * PricingEngine_Migration_Plan.md Phase 5 verification gate: dropping this
 * table requires confirming zero read traffic first. The `retrieved` hook
 * below is the temporary "deprecation-warning log line" the plan calls
 * for -- remove it (and this comment) once the bake period is over and the
 * table is actually dropped. It logs on every row load regardless of call
 * site (repository method, admin CRUD use case, relation eager-load), which
 * plain call-site grepping can't guarantee -- e.g. PayerContracts.vue's
 * legacy "Service price" picker still reads this table by design (kept as
 * the additive fallback for negotiated prices, see PayerContracts.vue),
 * so this is expected to log real traffic for a while yet.
 */
class BillingServiceCatalogItemModel extends Model
{
    use HasUuids;

    protected $table = 'billing_service_catalog_items';

    protected static function booted(): void
    {
        static::retrieved(function (self $item): void {
            Log::warning('[pricing-engine-phase5-bake] billing_service_catalog_items row read', [
                'id' => $item->getKey(),
                'route' => request()?->route()?->getName(),
                'context' => app()->runningInConsole() ? 'console' : 'http',
            ]);
        });
    }

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'facility_tier',
        'clinical_catalog_item_id',
        'service_code',
        'tariff_version',
        'service_name',
        'service_type',
        'department_id',
        'department',
        'unit',
        'base_price',
        'currency_code',
        'tax_rate_percent',
        'is_taxable',
        'effective_from',
        'effective_to',
        'description',
        'metadata',
        'codes',
        'status',
        'status_reason',
        'supersedes_billing_service_catalog_item_id',
        'price_unit',
        'units_per_pack',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'tariff_version' => 'integer',
            'tax_rate_percent' => 'decimal:2',
            'is_taxable' => 'boolean',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'metadata' => 'array',
            'codes' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function clinicalCatalogItem(): BelongsTo
    {
        return $this->belongsTo(ClinicalCatalogItemModel::class, 'clinical_catalog_item_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(DepartmentModel::class, 'department_id');
    }
}

<?php

namespace App\Modules\Revenue\Infrastructure\Models;

use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Revenue\Domain\ValueObjects\AuthorizationBasis;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Domain\ValueObjects\PayerClass;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceChargeModel extends Model
{
    use HasUuids;

    protected $table = 'service_charges';

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'float',
        'unit_price_minor' => 'integer',
        'gross_amount_minor' => 'integer',
        'discount_amount_minor' => 'integer',
        'tax_amount_minor' => 'integer',
        'net_amount_minor' => 'integer',
        'patient_responsibility_minor' => 'integer',
        'payer_responsibility_minor' => 'integer',
        'allocated_amount_minor' => 'integer',
        'status' => ServiceChargeStatus::class,
        'payer_class' => PayerClass::class,
        'authorization_basis' => AuthorizationBasis::class,
        'source_workflow_kind' => ChargeSourceKind::class,
        'authorized_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function chargeableItem(): BelongsTo
    {
        return $this->belongsTo(ChargeableItemModel::class, 'chargeable_item_id');
    }

    public function priceBookEntry(): BelongsTo
    {
        return $this->belongsTo(PriceBookEntryModel::class, 'price_book_entry_id');
    }

    public function netAmount(): Money
    {
        return Money::of((int) $this->net_amount_minor, (string) $this->currency_code);
    }

    public function patientResponsibility(): Money
    {
        return Money::of((int) $this->patient_responsibility_minor, (string) $this->currency_code);
    }

    public function allocatedAmount(): Money
    {
        return Money::of((int) $this->allocated_amount_minor, (string) $this->currency_code);
    }

    /**
     * What the patient still has to hand over before this charge clears.
     */
    public function outstandingAmount(): Money
    {
        return $this->patientResponsibility()->minus($this->allocatedAmount());
    }
}

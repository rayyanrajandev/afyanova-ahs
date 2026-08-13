<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingInvoiceLineItemSourceModel extends Model
{
    use HasUuids;

    protected $table = 'billing_invoice_line_item_sources';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'billing_invoice_id',
        'source_workflow_kind',
        'source_workflow_id',
        'line_item_index',
    ];

    protected function casts(): array
    {
        return [
            'line_item_index' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoiceModel::class, 'billing_invoice_id');
    }

    /**
     * Shared extraction logic for anything that needs an invoice's line items
     * reduced to their {kind, id} source refs -- used by the repository (to
     * populate this table) and by the write use cases (to build the source list
     * carried on InvoiceStatusChanged/InvoicePaymentRecorded/InvoicePaymentReversed).
     *
     * @param  array<int, mixed>|null  $lineItems
     * @return array<int, array{kind: string, id: string}>
     */
    public static function extractSourceRefs(?array $lineItems): array
    {
        $refs = [];

        foreach ($lineItems ?? [] as $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }

            $kind = trim((string) ($lineItem['sourceWorkflowKind'] ?? ''));
            $id = trim((string) ($lineItem['sourceWorkflowId'] ?? ''));
            if ($kind === '' || $id === '') {
                continue;
            }

            $refs[] = ['kind' => strtolower($kind), 'id' => $id];
        }

        return $refs;
    }
}

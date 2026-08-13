<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GLJournalEntryModel extends Model
{
    use HasUuids;

    protected $table = 'gl_journal_entries';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'reference_id',
        'reference_type',
        'account_code',
        'account_name',
        'debit_amount',
        'credit_amount',
        'entry_date',
        'posting_date',
        'description',
        'posted_by_user_id',
        'status',
        'batch_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'entry_date' => 'datetime',
            'posting_date' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the invoice this GL entry references
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoiceModel::class, 'reference_id', 'id')
            ->where('reference_type', 'invoice');
    }

    /**
     * Get the payment this GL entry references
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingInvoicePaymentModel::class, 'reference_id', 'id')
            ->where('reference_type', 'payment');
    }
}

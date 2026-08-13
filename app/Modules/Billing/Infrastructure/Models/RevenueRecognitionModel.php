<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevenueRecognitionModel extends Model
{
    use HasUuids;

    protected $table = 'revenue_recognition_records';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'billing_invoice_id',
        'recognition_date',
        'recognition_method',
        'amount_recognized',
        'amount_adjusted',
        'net_revenue',
        'gl_entry_ids',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_recognized' => 'decimal:2',
            'amount_adjusted' => 'decimal:2',
            'net_revenue' => 'decimal:2',
            'gl_entry_ids' => 'array',
            'recognition_date' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the invoice this revenue recognition is for
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoiceModel::class, 'billing_invoice_id', 'id');
    }

    /**
     * Get the GL entries for this revenue recognition
     */
    public function glEntries(): HasMany
    {
        return $this->hasMany(GLJournalEntryModel::class, 'reference_id', 'billing_invoice_id')
            ->where('reference_type', 'revenue_recognition');
    }
}

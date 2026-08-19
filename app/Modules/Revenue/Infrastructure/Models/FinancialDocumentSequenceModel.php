<?php

namespace App\Modules\Revenue\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FinancialDocumentSequenceModel extends Model
{
    use HasUuids;

    protected $table = 'financial_document_sequences';

    protected $guarded = [];

    protected $casts = [
        'next_value' => 'integer',
    ];
}

<?php

namespace App\Modules\Encounter\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EncounterClinicalDocumentModel extends Model
{
    use HasUuids;

    protected $table = 'encounter_clinical_documents';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'encounter_id',
        'patient_id',
        'tenant_id',
        'facility_id',
        'document_type',
        'title',
        'description',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size_bytes',
        'checksum_sha256',
        'status',
        'status_reason',
        'uploaded_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'uploaded_by_user_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

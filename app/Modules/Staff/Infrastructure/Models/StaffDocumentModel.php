<?php

namespace App\Modules\Staff\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StaffDocumentModel extends Model
{
    use HasUuids;

    protected $table = 'staff_documents';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'staff_profile_id',
        'tenant_id',
        'document_type',
        'title',
        'description',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size_bytes',
        'checksum_sha256',
        'issued_at',
        'expires_at',
        'verification_status',
        'verification_reason',
        'status',
        'status_reason',
        'uploaded_by_user_id',
        'verified_by_user_id',
        'verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'uploaded_by_user_id' => 'integer',
            'verified_by_user_id' => 'integer',
            'issued_at' => 'date',
            'expires_at' => 'date',
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}


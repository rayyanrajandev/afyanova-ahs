<?php

namespace App\Modules\MedicalRecord\Infrastructure\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalRecordSignerAttestationModel extends Model
{
    use HasUuids;

    protected $table = 'medical_record_signer_attestations';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'medical_record_id',
        'attested_by_user_id',
        'attestation_note',
        'attested_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function attestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attested_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attested_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use App\Modules\Platform\Application\Support\ClinicalCatalogBulkCsvSchema;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogItemStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateClinicalCatalogItemStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'itemIds' => ['required', 'array', 'min:1', 'max:'.ClinicalCatalogBulkCsvSchema::MAX_BULK_STATUS_IDS],
            'itemIds.*' => ['required', 'uuid', 'distinct'],
            'status' => ['required', Rule::in(ClinicalCatalogItemStatus::values())],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}

<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use App\Modules\Platform\Application\Support\ClinicalCatalogBulkCsvSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkImportClinicalCatalogItemsRequest extends FormRequest
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
            'dryRun' => ['nullable', 'boolean'],
            'mode' => ['required', Rule::in(['create', 'upsert'])],
            'rows' => ['required', 'array', 'min:1', 'max:'.ClinicalCatalogBulkCsvSchema::MAX_IMPORT_ROWS],
            'rows.*.rowNumber' => ['nullable', 'integer', 'min:1'],
            'rows.*.values' => ['nullable', 'array'],
            'rows.*.code' => ['nullable', 'string', 'max:100'],
            'rows.*.name' => ['nullable', 'string', 'max:255'],
        ];
    }
}

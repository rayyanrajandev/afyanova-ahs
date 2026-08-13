<?php

namespace App\Modules\Patient\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkImportPatientsRequest extends FormRequest
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
            'dryRun' => ['required', 'boolean'],
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*.rowNumber' => ['required', 'integer'],
            'rows.*.values' => ['required', 'array'],
        ];
    }
}

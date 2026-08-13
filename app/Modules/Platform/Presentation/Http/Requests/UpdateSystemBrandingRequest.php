<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSystemBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'systemName' => trim((string) $this->input('systemName', '')),
            'shortName' => $this->normalizeOptionalInput('shortName'),
            'mailFromName' => $this->normalizeOptionalInput('mailFromName'),
            'mailFromAddress' => $this->normalizeOptionalInput('mailFromAddress'),
            'mailReplyToAddress' => $this->normalizeOptionalInput('mailReplyToAddress'),
            'mailFooterText' => $this->normalizeOptionalInput('mailFooterText'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'systemName' => ['required', 'string', 'max:120'],
            'shortName' => ['nullable', 'string', 'max:40'],
            'mailFromName' => ['nullable', 'string', 'max:120'],
            'mailFromAddress' => ['nullable', 'email:rfc', 'max:190'],
            'mailReplyToAddress' => ['nullable', 'email:rfc', 'max:190'],
            'mailFooterText' => ['nullable', 'string', 'max:240'],
            'removeLogo' => ['nullable', 'boolean'],
            'logo' => ['nullable', 'file', 'max:3072', 'mimetypes:image/png,image/jpeg,image/webp'],
            'removeAppIcon' => ['nullable', 'boolean'],
            'appIcon' => ['nullable', 'file', 'max:1024', 'mimetypes:image/png', 'dimensions:ratio=1/1,min_width=128,min_height=128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.uploaded' => 'The logo failed to upload. Try a smaller PNG, JPG, or WebP file.',
            'logo.max' => 'The selected logo is larger than the 3MB upload limit.',
            'appIcon.uploaded' => 'The app icon failed to upload. Try a square PNG image.',
            'appIcon.max' => 'The selected app icon is larger than the 1MB upload limit.',
            'appIcon.dimensions' => 'The app icon must be a square PNG image that is at least 128 x 128 pixels.',
            'mailFromAddress.email' => 'Enter a valid sender email address.',
            'mailReplyToAddress.email' => 'Enter a valid reply-to email address.',
        ];
    }

    private function normalizeOptionalInput(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value !== '' ? $value : null;
    }
}

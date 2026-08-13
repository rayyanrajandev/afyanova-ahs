<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStaffDocumentRequest extends FormRequest
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
            'documentType' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'issuedAt' => ['nullable', 'date'],
            'expiresAt' => ['nullable', 'date', 'after_or_equal:issuedAt'],
            'file' => ['required', 'file', 'max:20480', 'mimetypes:application/pdf,image/jpeg,image/png,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $serverLimitLabel = $this->serverDocumentUploadLimitLabel();

        return [
            'file.uploaded' => "The file failed to upload. This environment currently allows files up to {$serverLimitLabel}.",
            'file.max' => 'The selected file is larger than the 20MB application limit.',
        ];
    }

    private function serverDocumentUploadLimitLabel(): string
    {
        $uploadMaxBytes = $this->parseIniSizeToBytes((string) ini_get('upload_max_filesize'));
        $postMaxBytes = $this->parseIniSizeToBytes((string) ini_get('post_max_size'));

        $effectiveMaxBytes = match (true) {
            $uploadMaxBytes > 0 && $postMaxBytes > 0 => min($uploadMaxBytes, $postMaxBytes),
            $uploadMaxBytes > 0 => $uploadMaxBytes,
            $postMaxBytes > 0 => $postMaxBytes,
            default => 20 * 1024 * 1024,
        };

        return $this->formatBytesLabel($effectiveMaxBytes);
    }

    private function parseIniSizeToBytes(string $value): int
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return 0;
        }

        $unit = substr($normalized, -1);
        $number = (float) $normalized;

        return match ($unit) {
            'g' => (int) round($number * 1024 * 1024 * 1024),
            'm' => (int) round($number * 1024 * 1024),
            'k' => (int) round($number * 1024),
            default => (int) round((float) $normalized),
        };
    }

    private function formatBytesLabel(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / (1024 * 1024 * 1024), 1, '.', ''), '0'), '.').'GB';
        }

        if ($bytes >= 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, '.', ''), '0'), '.').'MB';
        }

        if ($bytes >= 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.').'KB';
        }

        return $bytes.' bytes';
    }
}

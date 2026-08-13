<?php

namespace App\Support\Branding;

use App\Support\Settings\SystemSettingsManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SystemBrandingManager
{
    public const DEFAULT_SYSTEM_NAME = 'Afyanova AHS';

    public const DEFAULT_SHORT_NAME = 'Afyanova';

    public const MAIL_MARKDOWN_THEME = 'afyanova';

    private const DEFAULT_APP_ICON_PATH = 'apple-touch-icon.png';

    public function __construct(
        private readonly SystemSettingsManager $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function publicBranding(): array
    {
        $systemName = $this->resolveSystemName();
        $shortName = $this->resolveShortName();
        $logoPath = $this->logoPath();
        $logoUrl = $logoPath !== null ? $this->logoUrl($logoPath) : null;
        $appIconPath = $this->appIconPath();

        return [
            'systemName' => $systemName,
            'shortName' => $shortName,
            'displayName' => $shortName ?: $systemName,
            'logoUrl' => $logoUrl,
            'hasCustomLogo' => $logoUrl !== null,
            'appIconUrl' => $this->appIconUrl($appIconPath),
            'hasCustomAppIcon' => $appIconPath !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mailBranding(): array
    {
        $systemName = $this->resolveSystemName();
        $configuredFromName = $this->configuredMailFromName();
        $configuredFromAddress = $this->configuredMailFromAddress();
        $configuredFooterText = $this->configuredMailFooterText();

        return [
            'fromName' => $configuredFromName ?? $systemName,
            'fromAddress' => $configuredFromAddress ?? $this->defaultMailFromAddress(),
            'replyToAddress' => $this->resolveMailReplyToAddress(),
            'footerText' => $configuredFooterText ?? $this->defaultMailFooterText($systemName),
            'usesCustomFromName' => $configuredFromName !== null,
            'usesCustomFromAddress' => $configuredFromAddress !== null,
            'usesCustomFooterText' => $configuredFooterText !== null,
            'defaults' => [
                'fromAddress' => $this->defaultMailFromAddress(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function documentBranding(): array
    {
        $publicBranding = $this->publicBranding();
        $mailBranding = $this->mailBranding();

        return [
            'systemName' => $publicBranding['systemName'],
            'displayName' => $publicBranding['displayName'],
            'logoUrl' => $publicBranding['logoUrl'],
            'issuedByName' => $mailBranding['fromName'],
            'supportEmail' => $mailBranding['replyToAddress'] ?? $mailBranding['fromAddress'],
            'footerText' => $mailBranding['footerText'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function documentPdfBranding(): array
    {
        return array_merge($this->documentBranding(), [
            'logoDataUri' => $this->documentLogoDataUri(),
        ]);
    }

    public function systemName(): string
    {
        return $this->resolveSystemName();
    }

    public function mailFromName(): string
    {
        return $this->resolveMailFromName($this->resolveSystemName());
    }

    public function mailFromAddress(): string
    {
        return $this->resolveMailFromAddress();
    }

    public function mailReplyToAddress(): ?string
    {
        return $this->resolveMailReplyToAddress();
    }

    public function mailFooterText(): string
    {
        return $this->resolveMailFooterText($this->resolveSystemName());
    }

    public function emailHeaderLogoUrl(): string
    {
        $logoPath = $this->logoPath();

        if ($logoPath !== null) {
            return $this->logoUrl($logoPath, absolute: true);
        }

        return $this->appIconUrl($this->appIconPath(), absolute: true);
    }

    public function applyNotificationBranding(MailMessage $message): MailMessage
    {
        $fromName = $this->mailFromName();

        $message
            ->from($this->mailFromAddress(), $fromName)
            ->theme(self::MAIL_MARKDOWN_THEME);

        $replyToAddress = $this->mailReplyToAddress();

        if ($replyToAddress !== null) {
            $message->replyTo($replyToAddress, $fromName);
        }

        return $message;
    }

    public function logoPath(): ?string
    {
        $path = $this->storedLogoPath();
        if ($path === null) {
            return null;
        }

        try {
            return Storage::disk('local')->exists($path) ? $path : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function appIconPath(): ?string
    {
        $path = $this->storedAppIconPath();
        if ($path === null) {
            return null;
        }

        try {
            return Storage::disk('local')->exists($path) ? $path : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function update(array $attributes): array
    {
        $systemName = trim((string) ($attributes['systemName'] ?? ''));
        $shortName = $attributes['shortName'] ?? null;
        $logoFile = $attributes['logoFile'] ?? null;
        $removeLogo = (bool) ($attributes['removeLogo'] ?? false);
        $appIconFile = $attributes['appIconFile'] ?? null;
        $removeAppIcon = (bool) ($attributes['removeAppIcon'] ?? false);
        $mailFromName = $attributes['mailFromName'] ?? null;
        $mailFromAddress = $attributes['mailFromAddress'] ?? null;
        $mailReplyToAddress = $attributes['mailReplyToAddress'] ?? null;
        $mailFooterText = $attributes['mailFooterText'] ?? null;
        $previousLogoPath = $this->storedLogoPath();
        $uploadedLogoPath = null;
        $nextLogoPath = $previousLogoPath;
        $previousAppIconPath = $this->storedAppIconPath();
        $uploadedAppIconPath = null;
        $nextAppIconPath = $previousAppIconPath;

        if ($logoFile !== null) {
            $uploadedLogoPath = $this->storeLogo($logoFile);
            $nextLogoPath = $uploadedLogoPath;
        } elseif ($removeLogo) {
            $nextLogoPath = null;
        }

        if ($appIconFile !== null) {
            $uploadedAppIconPath = $this->storeAppIcon($appIconFile);
            $nextAppIconPath = $uploadedAppIconPath;
        } elseif ($removeAppIcon) {
            $nextAppIconPath = null;
        }

        try {
            $this->settings->putMany([
                'branding.system_name' => [
                    'group' => 'branding',
                    'type' => 'string',
                    'value' => trim($systemName),
                ],
                'branding.short_name' => [
                    'group' => 'branding',
                    'type' => 'string',
                    'value' => $this->normalizeOptionalString($shortName),
                ],
                'branding.logo_path' => [
                    'group' => 'branding',
                    'type' => 'string',
                    'value' => $nextLogoPath,
                ],
                'branding.app_icon_path' => [
                    'group' => 'branding',
                    'type' => 'string',
                    'value' => $nextAppIconPath,
                ],
                'branding.mail_from_name' => [
                    'group' => 'branding',
                    'type' => 'string',
                    'value' => $this->normalizeOptionalString($mailFromName),
                ],
                'branding.mail_from_address' => [
                    'group' => 'branding',
                    'type' => 'string',
                    'value' => $this->normalizeOptionalString($mailFromAddress),
                ],
                'branding.mail_reply_to_address' => [
                    'group' => 'branding',
                    'type' => 'string',
                    'value' => $this->normalizeOptionalString($mailReplyToAddress),
                ],
                'branding.mail_footer_text' => [
                    'group' => 'branding',
                    'type' => 'string',
                    'value' => $this->normalizeOptionalString($mailFooterText),
                ],
            ]);
        } catch (Throwable $exception) {
            if ($uploadedLogoPath !== null) {
                Storage::disk('local')->delete($uploadedLogoPath);
            }

            if ($uploadedAppIconPath !== null) {
                Storage::disk('local')->delete($uploadedAppIconPath);
            }

            throw $exception;
        }

        if ($uploadedLogoPath !== null && $previousLogoPath !== null && $previousLogoPath !== $uploadedLogoPath) {
            Storage::disk('local')->delete($previousLogoPath);
        }

        if ($uploadedLogoPath === null && $removeLogo && $previousLogoPath !== null) {
            Storage::disk('local')->delete($previousLogoPath);
        }

        if ($uploadedAppIconPath !== null && $previousAppIconPath !== null && $previousAppIconPath !== $uploadedAppIconPath) {
            Storage::disk('local')->delete($previousAppIconPath);
        }

        if ($uploadedAppIconPath === null && $removeAppIcon && $previousAppIconPath !== null) {
            Storage::disk('local')->delete($previousAppIconPath);
        }

        return [
            'branding' => $this->publicBranding(),
            'mail' => $this->mailBranding(),
        ];
    }

    private function resolveSystemName(): string
    {
        $configured = $this->settings->get('branding.system_name');
        $normalized = trim((string) $configured);

        return $normalized !== '' ? $normalized : self::DEFAULT_SYSTEM_NAME;
    }

    private function resolveShortName(): ?string
    {
        if (! $this->settings->has('branding.short_name')) {
            return self::DEFAULT_SHORT_NAME;
        }

        return $this->normalizeOptionalString($this->settings->get('branding.short_name'));
    }

    private function resolveMailFromName(string $systemName): string
    {
        return $this->configuredMailFromName() ?? $systemName;
    }

    private function resolveMailFromAddress(): string
    {
        return $this->configuredMailFromAddress() ?? $this->defaultMailFromAddress();
    }

    private function resolveMailReplyToAddress(): ?string
    {
        return $this->normalizeOptionalString($this->settings->get('branding.mail_reply_to_address'));
    }

    private function resolveMailFooterText(string $systemName): string
    {
        return $this->configuredMailFooterText() ?? $this->defaultMailFooterText($systemName);
    }

    private function configuredMailFromName(): ?string
    {
        return $this->normalizeOptionalString($this->settings->get('branding.mail_from_name'));
    }

    private function configuredMailFromAddress(): ?string
    {
        return $this->normalizeOptionalString($this->settings->get('branding.mail_from_address'));
    }

    private function configuredMailFooterText(): ?string
    {
        return $this->normalizeOptionalString($this->settings->get('branding.mail_footer_text'));
    }

    private function defaultMailFromAddress(): string
    {
        $configured = trim((string) config('mail.branding_defaults.from.address', 'hello@example.com'));

        return $configured !== '' ? $configured : 'hello@example.com';
    }

    private function defaultMailFooterText(string $systemName): string
    {
        return sprintf('Copyright %d %s. All rights reserved.', (int) date('Y'), $systemName);
    }

    private function storedLogoPath(): ?string
    {
        return $this->normalizeOptionalString($this->settings->get('branding.logo_path'));
    }

    private function storedAppIconPath(): ?string
    {
        return $this->normalizeOptionalString($this->settings->get('branding.app_icon_path'));
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function storeLogo(UploadedFile $logoFile): string
    {
        $extension = strtolower((string) ($logoFile->getClientOriginalExtension() ?: $logoFile->guessExtension() ?: 'png'));
        $filename = (string) Str::uuid().'.'.$extension;

        Storage::disk('local')->putFileAs(
            path: 'branding/logos',
            file: $logoFile,
            name: $filename,
        );

        return 'branding/logos/'.$filename;
    }

    private function storeAppIcon(UploadedFile $appIconFile): string
    {
        $filename = (string) Str::uuid().'.png';

        Storage::disk('local')->putFileAs(
            path: 'branding/app-icons',
            file: $appIconFile,
            name: $filename,
        );

        return 'branding/app-icons/'.$filename;
    }

    private function logoUrl(string $logoPath, bool $absolute = false): string
    {
        return route('branding.logo', ['v' => substr(sha1($logoPath), 0, 12)], $absolute);
    }

    private function appIconUrl(?string $appIconPath, bool $absolute = false): string
    {
        $version = $appIconPath !== null
            ? substr(sha1($appIconPath), 0, 12)
            : $this->defaultAppIconVersion();

        return route('branding.icon', ['v' => $version], $absolute);
    }

    private function defaultAppIconVersion(): string
    {
        $defaultPath = public_path(self::DEFAULT_APP_ICON_PATH);

        return is_file($defaultPath)
            ? (string) (filemtime($defaultPath) ?: 'default-v1')
            : 'default-v1';
    }

    private function documentLogoDataUri(): ?string
    {
        $logoPath = $this->logoPath();
        if ($logoPath !== null) {
            return $this->storageFileDataUri($logoPath);
        }

        $appIconPath = $this->appIconPath();
        if ($appIconPath !== null) {
            return $this->storageFileDataUri($appIconPath, 'image/png');
        }

        $defaultIconPath = public_path(self::DEFAULT_APP_ICON_PATH);

        return is_file($defaultIconPath)
            ? $this->fileDataUri($defaultIconPath, 'image/png')
            : null;
    }

    private function storageFileDataUri(string $path, ?string $fallbackMime = null): ?string
    {
        try {
            $contents = Storage::disk('local')->get($path);
        } catch (Throwable) {
            return null;
        }

        return $this->binaryToDataUri($contents, $path, $fallbackMime);
    }

    private function fileDataUri(string $path, ?string $fallbackMime = null): ?string
    {
        $contents = @file_get_contents($path);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        return $this->binaryToDataUri($contents, $path, $fallbackMime);
    }

    private function binaryToDataUri(string $contents, string $path, ?string $fallbackMime = null): ?string
    {
        if ($contents === '') {
            return null;
        }

        return sprintf(
            'data:%s;base64,%s',
            $this->mimeTypeFromPath($path, $fallbackMime),
            base64_encode($contents),
        );
    }

    private function mimeTypeFromPath(string $path, ?string $fallbackMime = null): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => $fallbackMime ?? 'application/octet-stream',
        };
    }
}

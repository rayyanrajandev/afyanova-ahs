<?php

use App\Models\User;
use App\Support\Settings\SystemSettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Storage::fake('local');
});

it('requires authentication for platform branding updates', function (): void {
    $this->withHeaders([
        'Accept' => 'application/json',
    ])->post('/api/v1/platform/admin/branding', [
        'systemName' => 'Afyanova AHS',
    ])->assertUnauthorized();
});

it('forbids platform branding updates without the branding permission', function (): void {
    $actor = makeBrandingActor();

    $this->actingAs($actor)
        ->withHeaders([
            'Accept' => 'application/json',
        ])->post('/api/v1/platform/admin/branding', [
            'systemName' => 'Afyanova AHS',
        ])->assertForbidden();
});

it('stores branding values and uploaded brand assets when authorized', function (): void {
    $actor = makeBrandingActor([
        'platform.settings.manage-branding',
    ]);

    $response = $this->actingAs($actor)
        ->withHeaders([
            'Accept' => 'application/json',
        ])->post('/api/v1/platform/admin/branding', [
            'systemName' => 'Afyanova Cloud',
            'shortName' => 'Afya',
            'mailFromName' => 'Afyanova Care Desk',
            'mailFromAddress' => 'no-reply@afyanova.so',
            'mailReplyToAddress' => 'support@afyanova.so',
            'mailFooterText' => 'Need help? Reply to this email or contact Afyanova support.',
            'logo' => UploadedFile::fake()->image('afyanova-logo.png', 180, 180),
            'appIcon' => UploadedFile::fake()->image('afyanova-app-icon.png', 256, 256),
        ])->assertOk()
        ->assertJsonPath('data.branding.systemName', 'Afyanova Cloud')
        ->assertJsonPath('data.branding.shortName', 'Afya')
        ->assertJsonPath('data.branding.displayName', 'Afya')
        ->assertJsonPath('data.branding.hasCustomLogo', true)
        ->assertJsonPath('data.branding.hasCustomAppIcon', true)
        ->assertJsonPath('data.mail.fromName', 'Afyanova Care Desk')
        ->assertJsonPath('data.mail.fromAddress', 'no-reply@afyanova.so')
        ->assertJsonPath('data.mail.replyToAddress', 'support@afyanova.so')
        ->assertJsonPath('data.mail.footerText', 'Need help? Reply to this email or contact Afyanova support.');

    expect(DB::table('system_settings')->where('key', 'branding.system_name')->value('value'))
        ->toBe('Afyanova Cloud');
    expect(DB::table('system_settings')->where('key', 'branding.short_name')->value('value'))
        ->toBe('Afya');
    expect(DB::table('system_settings')->where('key', 'branding.mail_from_name')->value('value'))
        ->toBe('Afyanova Care Desk');
    expect(DB::table('system_settings')->where('key', 'branding.mail_from_address')->value('value'))
        ->toBe('no-reply@afyanova.so');
    expect(DB::table('system_settings')->where('key', 'branding.mail_reply_to_address')->value('value'))
        ->toBe('support@afyanova.so');
    expect(DB::table('system_settings')->where('key', 'branding.mail_footer_text')->value('value'))
        ->toBe('Need help? Reply to this email or contact Afyanova support.');

    $storedLogoPath = DB::table('system_settings')
        ->where('key', 'branding.logo_path')
        ->value('value');

    expect($storedLogoPath)->not->toBeNull();
    Storage::disk('local')->assertExists($storedLogoPath);
    expect(str_starts_with((string) $response->json('data.branding.logoUrl'), '/branding/logo'))->toBeTrue();

    $storedAppIconPath = DB::table('system_settings')
        ->where('key', 'branding.app_icon_path')
        ->value('value');

    expect($storedAppIconPath)->not->toBeNull();
    Storage::disk('local')->assertExists($storedAppIconPath);
    expect(str_starts_with((string) $response->json('data.branding.appIconUrl'), '/branding/icon'))->toBeTrue();
});

it('removes an existing custom logo when the default mark is restored', function (): void {
    $actor = makeBrandingActor([
        'platform.settings.manage-branding',
    ]);

    $this->actingAs($actor)
        ->withHeaders([
            'Accept' => 'application/json',
        ])->post('/api/v1/platform/admin/branding', [
            'systemName' => 'Afyanova AHS',
            'shortName' => 'Afyanova',
            'logo' => UploadedFile::fake()->image('brand.png', 160, 160),
        ])->assertOk();

    $storedLogoPath = (string) DB::table('system_settings')
        ->where('key', 'branding.logo_path')
        ->value('value');

    Storage::disk('local')->assertExists($storedLogoPath);

    $this->actingAs($actor)
        ->withHeaders([
            'Accept' => 'application/json',
        ])->post('/api/v1/platform/admin/branding', [
            'systemName' => 'Afyanova AHS',
            'shortName' => 'Afyanova',
            'removeLogo' => '1',
        ])->assertOk()
        ->assertJsonPath('data.branding.hasCustomLogo', false)
        ->assertJsonPath('data.branding.logoUrl', null);

    Storage::disk('local')->assertMissing($storedLogoPath);
    expect(DB::table('system_settings')->where('key', 'branding.logo_path')->value('value'))
        ->toBeNull();
});

it('returns 404 for the public branding logo route when no logo has been uploaded', function (): void {
    $this->get('/branding/logo')
        ->assertNotFound();
});

it('serves the default branding app icon when no custom app icon has been uploaded', function (): void {
    $response = $this->get('/branding/icon')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('public');
    expect($cacheControl)->toContain('max-age=31536000');
    expect($cacheControl)->toContain('immutable');
});

it('serves the uploaded logo through the public branding logo route', function (): void {
    $actor = makeBrandingActor([
        'platform.settings.manage-branding',
    ]);

    $this->actingAs($actor)
        ->withHeaders([
            'Accept' => 'application/json',
        ])->post('/api/v1/platform/admin/branding', [
            'systemName' => 'Afyanova AHS',
            'shortName' => 'Afyanova',
            'logo' => UploadedFile::fake()->image('public-logo.png', 160, 160),
        ])->assertOk();

    $response = $this->get('/branding/logo')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('public');
    expect($cacheControl)->toContain('max-age=31536000');
    expect($cacheControl)->toContain('immutable');
});

it('removes an existing custom app icon when the default icon is restored', function (): void {
    $actor = makeBrandingActor([
        'platform.settings.manage-branding',
    ]);

    $this->actingAs($actor)
        ->withHeaders([
            'Accept' => 'application/json',
        ])->post('/api/v1/platform/admin/branding', [
            'systemName' => 'Afyanova AHS',
            'shortName' => 'Afyanova',
            'appIcon' => UploadedFile::fake()->image('custom-app-icon.png', 256, 256),
        ])->assertOk();

    $storedAppIconPath = (string) DB::table('system_settings')
        ->where('key', 'branding.app_icon_path')
        ->value('value');

    Storage::disk('local')->assertExists($storedAppIconPath);

    $response = $this->actingAs($actor)
        ->withHeaders([
            'Accept' => 'application/json',
        ])->post('/api/v1/platform/admin/branding', [
            'systemName' => 'Afyanova AHS',
            'shortName' => 'Afyanova',
            'removeAppIcon' => '1',
        ])->assertOk()
        ->assertJsonPath('data.branding.hasCustomAppIcon', false);

    Storage::disk('local')->assertMissing($storedAppIconPath);
    expect(DB::table('system_settings')->where('key', 'branding.app_icon_path')->value('value'))
        ->toBeNull();
    expect(str_starts_with((string) $response->json('data.branding.appIconUrl'), '/branding/icon'))->toBeTrue();
});

it('shares the saved branding payload with the inertia admin page', function (): void {
    $actor = makeBrandingActor([
        'platform.settings.manage-branding',
    ]);

    app(SystemSettingsManager::class)->putMany([
        'branding.system_name' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'Afyanova Cloud',
        ],
        'branding.short_name' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'Afya',
        ],
        'branding.logo_path' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => null,
        ],
        'branding.app_icon_path' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => null,
        ],
        'branding.mail_from_name' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'Afyanova Care Desk',
        ],
        'branding.mail_from_address' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'no-reply@afyanova.so',
        ],
        'branding.mail_reply_to_address' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'support@afyanova.so',
        ],
        'branding.mail_footer_text' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'Need help? Reply to this email or contact Afyanova support.',
        ],
    ]);

    $this->actingAs($actor)
        ->get('/platform/admin/branding')
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/admin/branding/Index')
            ->where('name', 'Afyanova Cloud')
            ->where('branding.systemName', 'Afyanova Cloud')
            ->where('branding.shortName', 'Afya')
            ->where('branding.displayName', 'Afya')
            ->where('branding.logoUrl', null)
            ->where('branding.hasCustomLogo', false)
            ->where('branding.hasCustomAppIcon', false)
            ->where('branding.appIconUrl', fn (string $value): bool => str_starts_with($value, '/branding/icon'))
            ->where('mailBranding.fromName', 'Afyanova Care Desk')
            ->where('mailBranding.fromAddress', 'no-reply@afyanova.so')
            ->where('mailBranding.replyToAddress', 'support@afyanova.so')
            ->where('mailBranding.footerText', 'Need help? Reply to this email or contact Afyanova support.')
            ->where('mailBranding.defaults.fromAddress', config('mail.branding_defaults.from.address')));
});

function makeBrandingActor(array $permissions = []): User
{
    return makeUserWithRole($permissions);
}

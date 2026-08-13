<?php

use App\Models\User;
use App\Notifications\UserCredentialLinkNotification;
use App\Support\Settings\SystemSettingsManager;
use Illuminate\Support\Facades\Notification;

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, UserCredentialLinkNotification::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, UserCredentialLinkNotification::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, UserCredentialLinkNotification::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        expect($user->fresh()?->email_verified_at)->not->toBeNull();

        return true;
    });
});

test('unverified users receive set password wording', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create([
        'name' => 'Invite User',
    ]);

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, UserCredentialLinkNotification::class, function ($notification) use ($user) {
        $mail = $notification->toMail($user);

        expect($mail->subject)->toBe('Set your password');
        expect($mail->actionText)->toBe('Set Password');
        expect($mail->introLines)->toContain('An account has been prepared for you in '.config('app.name', 'AfyaNova').'.');

        return true;
    });
});

test('credential link emails use the saved mail branding identity', function () {
    app(SystemSettingsManager::class)->putMany([
        'branding.system_name' => [
            'group' => 'branding',
            'type' => 'string',
            'value' => 'Afyanova Cloud',
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

    Notification::fake();

    $user = User::factory()->unverified()->create([
        'name' => 'Invite User',
    ]);

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, UserCredentialLinkNotification::class, function ($notification) use ($user) {
        $mail = $notification->toMail($user);
        $rendered = (string) $mail->render();

        expect($mail->from)->toBe(['no-reply@afyanova.so', 'Afyanova Care Desk']);
        expect($mail->replyTo)->toBe([['support@afyanova.so', 'Afyanova Care Desk']]);
        expect($mail->theme)->toBe('afyanova');
        expect($mail->introLines)->toContain('An account has been prepared for you in Afyanova Cloud.');
        expect($rendered)->toContain('Need help? Reply to this email or contact Afyanova support.');
        expect($rendered)->toContain('Afyanova Cloud');

        return true;
    });
});

test('verified users receive reset password wording', function () {
    Notification::fake();

    $user = User::factory()->create([
        'name' => 'Verified User',
        'email_verified_at' => now(),
    ]);

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, UserCredentialLinkNotification::class, function ($notification) use ($user) {
        $mail = $notification->toMail($user);

        expect($mail->subject)->toBe('Reset your password');
        expect($mail->actionText)->toBe('Reset Password');
        expect($mail->introLines)->toContain('A password reset was requested for your account.');

        return true;
    });
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});

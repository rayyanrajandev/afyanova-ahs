<?php

use App\Models\User;
use App\Notifications\UserEmailVerificationNotification;
use App\Support\Settings\SystemSettingsManager;
use Illuminate\Support\Facades\Notification;

test('sends verification notification', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('home'));

    Notification::assertSentTo($user, UserEmailVerificationNotification::class);
});

test('verification emails use the saved mail branding identity', function () {
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
    ]);

    Notification::fake();

    $user = User::factory()->unverified()->create([
        'name' => 'Verification User',
    ]);

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('home'));

    Notification::assertSentTo($user, UserEmailVerificationNotification::class, function ($notification) use ($user) {
        $mail = $notification->toMail($user);

        expect($mail->from)->toBe(['no-reply@afyanova.so', 'Afyanova Care Desk']);
        expect($mail->replyTo)->toBe([['support@afyanova.so', 'Afyanova Care Desk']]);
        expect($mail->theme)->toBe('afyanova');
        expect($mail->subject)->toBe('Verify your email address');
        expect($mail->introLines)->toContain('Welcome to Afyanova Cloud.');

        return true;
    });
});

test('does not send verification notification if email is verified', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('dashboard', absolute: false));

    Notification::assertNothingSent();
});

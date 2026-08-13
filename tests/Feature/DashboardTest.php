<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users without roles are redirected to pending setup', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('pending-setup'));
});

test('authenticated users with active role can visit the dashboard', function () {
    $user = User::factory()->create();
    $role = App\Modules\Platform\Infrastructure\Models\RoleModel::query()->create([
        'code' => 'ADMIN.FACILITY',
        'name' => 'Facility Admin',
        'status' => 'active',
    ]);
    $user->roles()->syncWithoutDetaching([$role->id]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});
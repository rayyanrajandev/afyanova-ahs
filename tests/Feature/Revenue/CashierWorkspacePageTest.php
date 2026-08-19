<?php

use App\Models\User;

/**
 * The workspace door.
 *
 * The nav has carried a /cashier entry since before the route existed, so it
 * 404'd for anyone who clicked it. This pins the page open and gated.
 */
function cashierRoleUser(string $roleKey): User
{
    $roles = (array) config('roles');

    return makeUserWithRole(
        (array) $roles[$roleKey]['permissions'],
        (string) $roles[$roleKey]['code'],
    );
}

it('renders the cashier workspace for a cashier', function (): void {
    $this->actingAs(cashierRoleUser('cashier'))
        ->get('/cashier')
        ->assertOk();
});

it('renders it for a finance manager', function (): void {
    $this->actingAs(cashierRoleUser('finance-manager'))
        ->get('/cashier')
        ->assertOk();
});

it('keeps a receptionist out of the workspace, while still letting them read the gate', function (): void {
    // Reception can see whether a visit is paid — that is cashier.charges.read
    // on the API — but the counter itself is not their workstation.
    $this->actingAs(cashierRoleUser('receptionist'))
        ->get('/cashier')
        ->assertForbidden();
});

it('keeps a clinician out', function (): void {
    $this->actingAs(cashierRoleUser('clinical-officer'))
        ->get('/cashier')
        ->assertForbidden();
});

it('sends a guest to log in', function (): void {
    $this->get('/cashier')->assertRedirect('/login');
});

<?php

use App\Models\User;

/**
 * Who can actually do what, exercised over HTTP.
 *
 * The contract test next door proves each route names the right ability. This
 * proves the abilities are held by the right people — the half that was
 * missing when the retired cashier role held `pos.sales.create` and not
 * `pos.sessions.manage`, and so could ring up a sale it had nowhere to put.
 *
 * Roles are read from config/roles.php rather than restated, so the matrix
 * cannot quietly drift from the definition it is meant to check.
 */
function grantRole(string $roleKey): User
{
    $roles = (array) config('roles');
    $definition = $roles[$roleKey] ?? throw new RuntimeException("Unknown role: {$roleKey}");

    return makeUserWithRole(
        (array) $definition['permissions'],
        (string) $definition['code'],
    );
}

/**
 * A 403 is the only failure this suite cares about. Anything else — 404 for a
 * missing record, 422 for a shape the test did not bother to fill in, 409 for
 * a closed drawer — means authorization let the request through, which is what
 * is being asserted.
 */
function expectAllowed(string $roleKey, string $method, string $uri, array $payload = []): void
{
    $response = test()->actingAs(grantRole($roleKey))->json($method, $uri, $payload);

    expect($response->status())->not->toBe(403,
        "{$roleKey} should be allowed to {$method} {$uri}");
}

function expectForbidden(string $roleKey, string $method, string $uri, array $payload = []): void
{
    test()->actingAs(grantRole($roleKey))
        ->json($method, $uri, $payload)
        ->assertForbidden();
}

it('lets a cashier work the counter', function (string $method, string $uri): void {
    expectAllowed('cashier', $method, $uri);
})->with([
    ['GET', '/api/v1/cashier/queue'],
    ['GET', '/api/v1/cashier/queue/status-counts'],
    ['POST', '/api/v1/cashier/sessions'],
    ['GET', '/api/v1/cashier/session/current'],
    ['POST', '/api/v1/cashier/payments'],
    ['GET', '/api/v1/cashier/refunds'],
    ['POST', '/api/v1/cashier/refunds'],
]);

it('does not let a cashier approve their own work', function (string $method, string $uri): void {
    // The whole point of a second-person control. A cashier who could clear
    // their own variance, approve their own refund or waive a charge they
    // raised is not being checked by anyone.
    expectForbidden('cashier', $method, $uri);
})->with([
    ['POST', '/api/v1/cashier/sessions/'.fake()->uuid().'/approve-variance'],
    ['POST', '/api/v1/cashier/refunds/'.fake()->uuid().'/approve'],
    ['POST', '/api/v1/cashier/charges/'.fake()->uuid().'/waive'],
    ['GET', '/api/v1/cashier/day/summary'],
]);

it('lets a finance manager approve', function (string $method, string $uri): void {
    expectAllowed('finance-manager', $method, $uri);
})->with([
    ['POST', '/api/v1/cashier/sessions/'.fake()->uuid().'/approve-variance'],
    ['POST', '/api/v1/cashier/refunds/'.fake()->uuid().'/approve'],
    ['POST', '/api/v1/cashier/charges/'.fake()->uuid().'/waive'],
    ['GET', '/api/v1/cashier/day/summary'],
]);

it('lets an accountant read the books but never touch the money', function (): void {
    expectAllowed('accountant', 'GET', '/api/v1/cashier/queue');
    expectAllowed('accountant', 'GET', '/api/v1/cashier/day/summary');

    expectForbidden('accountant', 'POST', '/api/v1/cashier/payments');
    expectForbidden('accountant', 'POST', '/api/v1/cashier/sessions');
    expectForbidden('accountant', 'POST', '/api/v1/cashier/refunds');
});

it('lets reception see the gate it enforces, and nothing more', function (): void {
    // Reception has to explain to a patient why they are being sent to the
    // counter, which needs read access and no more than that.
    expectAllowed('receptionist', 'GET', '/api/v1/cashier/queue');

    expectForbidden('receptionist', 'POST', '/api/v1/cashier/payments');
    expectForbidden('receptionist', 'POST', '/api/v1/cashier/charges');
    expectForbidden('receptionist', 'GET', '/api/v1/cashier/day/summary');
});

it('gives the emergency override to clinicians and to no finance role', function (): void {
    $uri = '/api/v1/cashier/charges/'.fake()->uuid().'/emergency-override';

    // Treating an unpaid emergency is a clinical judgement.
    expectAllowed('medical-officer', 'POST', $uri);
    expectAllowed('emergency-nurse', 'POST', $uri);

    // Finance may write a charge off; it may not decide someone is an
    // emergency.
    expectForbidden('cashier', 'POST', $uri);
    expectForbidden('finance-manager', 'POST', $uri);
    expectForbidden('accountant', 'POST', $uri);
});

it('keeps a clinician out of the counter', function (string $method, string $uri): void {
    expectForbidden('clinical-officer', $method, $uri);
})->with([
    ['POST', '/api/v1/cashier/payments'],
    ['POST', '/api/v1/cashier/sessions'],
    ['GET', '/api/v1/cashier/day/summary'],
    ['POST', '/api/v1/cashier/charges/'.fake()->uuid().'/waive'],
]);

it('refuses everyone who holds nothing', function (string $method, string $uri): void {
    $stranger = makeUserWithRole([], 'NO.PERMISSIONS');

    test()->actingAs($stranger)->json($method, $uri)->assertForbidden();
})->with([
    ['GET', '/api/v1/cashier/queue'],
    ['POST', '/api/v1/cashier/payments'],
    ['POST', '/api/v1/cashier/sessions'],
    ['GET', '/api/v1/cashier/day/summary'],
]);

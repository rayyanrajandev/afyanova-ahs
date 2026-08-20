<?php

/**
 * Every nursing route refuses a user who lacks its permission.
 *
 * The workspace ships nineteen routes behind six distinct `can:` middlewares
 * and had no authorization test of any kind (2026-08-19 workspace maturity
 * audit, finding D4). A permission that is never asserted is a permission that
 * can be dropped from a route in a refactor with nothing to notice — which is
 * exactly how the delete visit-note route once went missing.
 *
 * Asserted in both directions on purpose: a test that only checks 403 stays
 * green if someone locks a route down so hard that nurses cannot use it either.
 */

use App\Models\User;
use Tests\Feature\Nursing\NursingTestSupport;

function userWithNoPermissions(): User
{
    return makeUserWithRole([], 'NO.PERMISSIONS');
}

dataset('nursing routes', function (): array {
    $id = '00000000-0000-4000-8000-000000000000';

    return [
        'worklist' => ['get', '/api/v1/nursing/tasks'],
        'patients' => ['get', '/api/v1/nursing/patients'],
        'active visit' => ['get', "/api/v1/nursing/active-visit/{$id}"],
        'latest vitals' => ['get', "/api/v1/nursing/vitals/{$id}"],
        'record vitals' => ['post', '/api/v1/nursing/vitals'],
        'complete assessment' => ['post', "/api/v1/nursing/assessments/{$id}"],
        'admission' => ['post', '/api/v1/nursing/admissions'],
        'return to reception' => ['post', "/api/v1/nursing/return-to-reception/{$id}"],
        'claim for nursing' => ['post', "/api/v1/nursing/visits/{$id}/claim"],
        'release from nursing' => ['post', "/api/v1/nursing/visits/{$id}/release"],
        'flow timeline' => ['get', "/api/v1/nursing/patients/{$id}/flow-timeline"],
        'visit timeline' => ['get', '/api/v1/nursing/visit-timeline'],
        'mar' => ['get', '/api/v1/nursing/mar'],
        'department options' => ['get', '/api/v1/nursing/department-options'],
        'add visit note' => ['post', "/api/v1/nursing/visit-notes/{$id}"],
        'read visit notes' => ['get', "/api/v1/nursing/visit-notes/{$id}"],
        'replace visit notes' => ['put', "/api/v1/nursing/visit-notes/{$id}"],
        'delete visit note' => ['delete', "/api/v1/nursing/visit-notes/{$id}"],
    ];
});

it('refuses a user who holds none of the nursing permissions', function (string $method, string $uri): void {
    $this->actingAs(userWithNoPermissions())
        ->json($method, $uri)
        ->assertForbidden();
})->with('nursing routes');

it('does not refuse a nurse who holds the role', function (string $method, string $uri): void {
    // Anything but 403. A 404 or 422 means the request got past authorization
    // and failed on its own merits, which is what this asserts.
    $response = $this->actingAs(NursingTestSupport::nurse())->json($method, $uri);

    expect($response->status())->not->toBe(403);
})->with('nursing routes');

it('refuses an unauthenticated request outright', function (): void {
    $this->getJson('/api/v1/nursing/tasks')->assertUnauthorized();
});

it('gives the nursing role every permission its own routes require', function (): void {
    // Catches the opposite failure to the matrix above: a route gated on a
    // permission nobody in nursing actually holds is unreachable in production
    // and invisible in a test that only ever asserts 403.
    $required = [
        'service.requests.read', 'service.requests.create', 'patients.read',
        'patient.vitals.record', 'medical.records.create', 'pharmacy.orders.read',
        'appointments.read',
    ];

    $held = (array) ((array) config('roles'))['nurse-officer']['permissions'];

    expect(array_values(array_diff($required, $held)))->toBe([]);
});

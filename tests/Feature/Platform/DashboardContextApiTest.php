<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires authentication for dashboard context endpoint', function (): void {
    $this->getJson('/api/v1/dashboard/context')
        ->assertUnauthorized();
});

it('returns dashboard context for authenticated user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard/context')
        ->assertOk()
        ->assertJsonPath('data.schemaVersion', 'dashboard-context.v2')
        ->assertJsonStructure([
            'data' => [
                'defaultWorkflowKey',
                'eligibleWorkflowKeys',
                'workflows' => [
                    '*' => ['key', 'label', 'description', 'modules', 'widgets'],
                ],
                'canSwitchWorkflow',
                'session' => ['roleCodes', 'permissionCount'],
            ],
        ]);
});

it('includes operations workflow when credentialing permissions are granted', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('staff.read');
    $user->givePermissionTo('staff.credentialing.read');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/dashboard/context')
        ->assertOk();

    expect($response->json('data.eligibleWorkflowKeys'))->toContain('operations');

    $workflowKeys = array_column($response->json('data.workflows'), 'key');
    expect($workflowKeys)->toContain('operations');
});

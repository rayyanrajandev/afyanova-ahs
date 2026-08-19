<?php

use Illuminate\Support\Facades\DB;

/**
 * Proves the feature harness reaches a migrated PostgreSQL test database —
 * and, just as importantly, that it is the *test* database. A green run here
 * is the precondition for every phase that follows.
 */
it('runs feature tests against the postgres test database', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
    expect(DB::connection()->getDatabaseName())->toContain('test');
});

it('has run the migrations', function (): void {
    expect(DB::getSchemaBuilder()->hasTable('patients'))->toBeTrue();
    expect(DB::getSchemaBuilder()->hasTable('chargeable_items'))->toBeTrue();
});

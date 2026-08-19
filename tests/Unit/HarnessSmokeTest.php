<?php

/**
 * Proves the Pest harness boots and that unit tests run without touching the
 * framework or the database. If this fails, nothing else in the suite is
 * trustworthy.
 */
it('runs unit tests without a database', function (): void {
    expect(1 + 1)->toBe(2);
});

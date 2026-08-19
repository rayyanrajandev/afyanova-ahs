<?php

use Illuminate\Support\Facades\Route;

/**
 * Every cashier route carries the ability it is supposed to.
 *
 * This is the test class whose absence let three authorization bugs ship:
 * `medical.records.update`, `laboratory.orders.update-status`, and the
 * retired cashier role that could ring up a sale but not open the drawer it
 * belonged to. A route's guard is easy to get wrong and invisible in review;
 * pinning the whole table makes a change deliberate.
 *
 * @var array<string, string>
 */
const CASHIER_ROUTE_ABILITIES = [
    'cashier.queue' => 'cashier.charges.read',
    'cashier.queue.status-counts' => 'cashier.charges.read',
    'cashier.patients' => 'patients.read',
    'cashier.patients.show' => 'patients.read',
    'cashier.patients.charges' => 'cashier.charges.read',
    'cashier.patients.payments' => 'cashier.payments.read',
    'cashier.patients.flow-timeline' => 'cashier.charges.read',
    'cashier.charges.show' => 'cashier.charges.read',
    'cashier.catalog' => 'cashier.charges.create',
    'cashier.charges.store' => 'cashier.charges.create',
    'cashier.charges.cancel' => 'cashier.charges.cancel',
    'cashier.charges.waive' => 'cashier.waivers.approve',
    'cashier.charges.emergency-override' => 'cashier.charges.emergency-override',
    'cashier.payments.store' => 'cashier.payments.record',
    'cashier.payments.show' => 'cashier.payments.read',
    'cashier.payments.reverse' => 'cashier.payments.reverse',
    'cashier.receipts.show' => 'cashier.receipts.read',
    'cashier.receipts.reprint' => 'cashier.receipts.reprint',
    'cashier.session.current' => 'cashier.sessions.read',
    'cashier.sessions.store' => 'cashier.sessions.open',
    'cashier.sessions.movements.store' => 'cashier.sessions.move-cash',
    'cashier.sessions.close' => 'cashier.sessions.close',
    'cashier.sessions.approve-variance' => 'cashier.sessions.approve-variance',
    'cashier.sessions.summary' => 'cashier.sessions.read',
    'cashier.refunds.index' => 'cashier.refunds.request',
    'cashier.refunds.store' => 'cashier.refunds.request',
    'cashier.refunds.approve' => 'cashier.refunds.approve',
    'cashier.refunds.reject' => 'cashier.refunds.approve',
    'cashier.day.summary' => 'cashier.reports.read',
];

it('guards every cashier route with its intended ability', function (string $routeName, string $ability): void {
    $route = Route::getRoutes()->getByName($routeName);

    expect($route)->not->toBeNull("route {$routeName} is not registered");

    // The dataset name identifies the route, so a failure already says which
    // one; toContain() treats a second argument as another needle, not a
    // message.
    expect($route->gatherMiddleware())->toContain('can:'.$ability);
})->with(array_map(
    static fn (string $name, string $ability): array => [$name, $ability],
    array_keys(CASHIER_ROUTE_ABILITIES),
    array_values(CASHIER_ROUTE_ABILITIES),
));

it('leaves no cashier route unguarded', function (): void {
    $unguarded = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with((string) $route->uri(), 'api/v1/cashier')) {
            continue;
        }

        $hasAbility = collect($route->gatherMiddleware())
            ->contains(fn (mixed $m): bool => is_string($m) && str_starts_with($m, 'can:'));

        if (! $hasAbility) {
            $unguarded[] = $route->getName() ?? $route->uri();
        }
    }

    expect($unguarded)->toBe([]);
});

it('pins the whole cashier surface, so a new route cannot slip in untested', function (): void {
    $registered = [];

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with((string) $route->uri(), 'api/v1/cashier')) {
            $registered[] = (string) $route->getName();
        }
    }

    sort($registered);
    $expected = array_keys(CASHIER_ROUTE_ABILITIES);
    sort($expected);

    expect($registered)->toBe($expected);
});

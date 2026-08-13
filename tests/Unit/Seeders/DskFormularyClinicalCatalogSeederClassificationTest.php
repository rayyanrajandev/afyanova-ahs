<?php

use Database\Seeders\DskFormularyClinicalCatalogSeeder;

/**
 * Guards against a future seeder edit silently reclassifying (or failing to
 * classify) an IV fluid catalog row, which would reopen the "0.9% treated as
 * the dose" bug for that item. Uses reflection instead of running the full
 * seeder, since DskFormularyClinicalCatalogSeeder::run() requires a seeded
 * DSK facility/department -- the classification logic itself needs neither.
 */
function deriveCatalogFields(DskFormularyClinicalCatalogSeeder $seeder, string $code): array
{
    $reflection = new ReflectionClass($seeder);

    $reflection->getMethod('buildOverrides')->invoke($seeder);

    $items = $reflection->getMethod('items')->invoke($seeder);

    $item = collect($items)->firstWhere('code', $code);
    expect($item)->not->toBeNull("Fixture item with code {$code} was not found in the seeder's item list.");

    $deriveFields = $reflection->getMethod('deriveFields');

    return $deriveFields->invoke($seeder, $item);
}

it('classifies pure crystalloid/electrolyte IV fluids as iv fluid with a structured container volume', function (): void {
    $seeder = new DskFormularyClinicalCatalogSeeder();

    foreach (['MED-NS-IV500', 'MED-D5-IV500', 'MED-DNS-IV500', 'MED-RL-IV500'] as $code) {
        $derived = deriveCatalogFields($seeder, $code);

        expect($derived['dosage_form'])->toBe('iv fluid');
        expect($derived['route'])->toBe('intravenous');
        expect($derived['container_volume_value'])->toBe(500);
        expect($derived['container_volume_unit'])->toBe('ml');
    }
});

it('keeps drug-in-solution IV infusions classified as injections, not iv fluid', function (): void {
    $seeder = new DskFormularyClinicalCatalogSeeder();

    foreach (['MED-PARA-IV100', 'MED-CIPRO-IV100', 'MED-FLUC-IV100', 'MED-METRO-IV100'] as $code) {
        $derived = deriveCatalogFields($seeder, $code);

        expect($derived['dosage_form'])->toBe('injection');
        expect($derived['route'])->toBe('intravenous');
        expect($derived['container_volume_value'])->toBeNull();
        expect($derived['container_volume_unit'])->toBeNull();
    }
});

it('leaves a plain oral tablet classification unaffected by the IV fluid override', function (): void {
    $seeder = new DskFormularyClinicalCatalogSeeder();

    $derived = deriveCatalogFields($seeder, 'MED-PARA-500-001');

    expect($derived['dosage_form'])->toBe('tablet');
    expect($derived['route'])->toBe('oral');
    expect($derived['container_volume_value'])->toBeNull();
    expect($derived['container_volume_unit'])->toBeNull();
});

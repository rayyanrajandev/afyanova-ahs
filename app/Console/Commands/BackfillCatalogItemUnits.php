<?php

namespace App\Console\Commands;

use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Console\Command;

class BackfillCatalogItemUnits extends Command
{
    protected $signature = 'catalog:backfill-item-units {--dry-run : Preview changes without writing to the database}';
    protected $description = 'Backfill stockUnit, dispensingUnit, and conversionFactor into clinical catalog item metadata';

    public function handle(): void
    {
        $isDryRun = $this->option('dry-run');

        $this->info('Starting clinical catalog item units backfill...');
        if ($isDryRun) {
            $this->warn('DRY RUN MODE — no data will be written.');
        }

        $stats = [
            'items_scanned' => 0,
            'stock_unit_added' => 0,
            'dispensing_unit_added' => 0,
            'items_unchanged' => 0,
        ];

        $query = ClinicalCatalogItemModel::query()
            ->where('catalog_type', ClinicalCatalogType::FORMULARY_ITEM->value)
            ->orderBy('code');

        $items = $query->get();

        foreach ($items as $item) {
            $stats['items_scanned']++;

            $metadata = is_array($item->metadata) ? $item->metadata : [];
            $changed = false;

            if (! isset($metadata['stockUnit'])) {
                $metadata['stockUnit'] = $item->unit ?? 'each';
                $stats['stock_unit_added']++;
                $changed = true;
            }

            if (! isset($metadata['dispensingUnit'])) {
                $metadata['dispensingUnit'] = $item->unit ?? 'each';
                $stats['dispensing_unit_added']++;
                $changed = true;
            }

            if (! $changed) {
                $stats['items_unchanged']++;
                continue;
            }

            if (! $isDryRun) {
                $item->metadata = $metadata;
                $item->save();
            }

            $this->line(
                sprintf(
                    '  %s: stockUnit=%s, dispensingUnit=%s',
                    $item->code,
                    $metadata['stockUnit'],
                    $metadata['dispensingUnit'],
                ),
            );
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Formulary items scanned', $stats['items_scanned']],
                ['stockUnit added', $stats['stock_unit_added']],
                ['dispensingUnit added', $stats['dispensing_unit_added']],
                ['Items unchanged', $stats['items_unchanged']],
            ],
        );

        $this->info('Backfill complete.');
    }
}

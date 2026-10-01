<?php

namespace App\Console\Commands;

use App\Services\CatalogImporter;
use App\Services\YouTube\QuotaGuard;
use Illuminate\Console\Command;

class ImportCatalogPlaylist extends Command
{
    protected $signature = 'catalog:import
                            {playlist* : Identyfikatory publicznych playlist YouTube}
                            {--genre= : Gatunek przypisany wszystkim utworom}
                            {--not-wedding-safe : Oznacz jako nieodpowiednie na wesele}';

    protected $description = 'Imports YouTube playlists into the catalogue (1 quota unit per 50 tracks)';

    public function handle(): int
    {
        $importer = CatalogImporter::make();
        $quota = QuotaGuard::fromConfig();

        $this->newLine();
        $this->line('  Quota before the import: <fg=yellow>'.$quota->remaining().'</> units');
        $this->newLine();

        $total = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'units' => 0];

        foreach ($this->argument('playlist') as $playlistId) {
            $this->line("  Importuje <fg=cyan>{$playlistId}</> ...");

            $stats = $importer->importPlaylist(
                $playlistId,
                $this->option('genre'),
                ! $this->option('not-wedding-safe'),
            );

            $this->line(sprintf(
                '    dodane: <fg=green>%d</>  zaktualizowane: %d  pominiete: %d  koszt: %d jedn.',
                $stats['added'], $stats['updated'], $stats['skipped'], $stats['units']
            ));

            foreach ($total as $key => $_) {
                $total[$key] += $stats[$key];
            }
        }

        $this->newLine();
        $this->info('  Added in total: '.$total['added'].', cost: '.$total['units'].' units');
        $this->line('  For comparison, searching would have cost '
            .number_format(($total['added'] + $total['updated']) * 100).' units.');
        $this->line('  Quota after the import: <fg=yellow>'.QuotaGuard::fromConfig()->remaining().'</> units');
        $this->newLine();

        return self::SUCCESS;
    }
}

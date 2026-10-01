<?php

namespace App\Console\Commands;

use App\Models\CatalogTrack;
use App\Services\CatalogImporter;
use App\Services\YouTube\QuotaGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Filling the catalogue overnight.
 *
 * It reads a list of playlists from a file and imports them one by one, watching
 * three things:
 *
 *  1. THE BUDGET - it never drops below the reserve the search needs. Importing
 *     a playlist costs 1 unit per 50 tracks, so even tens of thousands of tracks
 *     are a fraction of the daily quota - but the script is meant to run
 *     unattended, so the guard has to be hard.
 *
 *  2. RESUMABILITY - finished playlists are ticked off in a state file, so
 *     stopping and starting again does not begin from nothing.
 *
 *  3. COURTESY - a pause between playlists, so as not to hammer the API with a
 *     run of requests all night.
 */
class HarvestCatalog extends Command
{
    protected $signature = 'catalog:harvest
                            {--file=playlisty.txt : File with the list of playlists (in storage/app)}
                            {--pause=3 : Pause between playlists, in seconds}
                            {--reserve=3000 : How many units to leave for guest searches}
                            {--fresh : Ignore the saved progress}';

    protected $description = 'Fills the catalogue from a list of playlists - it can run all night';

    private const STAN = 'harvest-postep.json';

    public function handle(): int
    {
        $file = $this->option('file');

        if (! Storage::exists($file)) {
            $this->error("  No file {$file} in storage/app.");
            $this->line('  Format: jedna playlista na linie, opcjonalnie "ID  gatunek".');

            return self::FAILURE;
        }

        $zadania = $this->readJobs($file);
        $zrobione = $this->option('fresh') ? [] : $this->readProgress();

        $todo = array_filter($zadania, fn ($z) => ! in_array($z['id'], $zrobione, true));

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>FILLING THE CATALOGUE OVERNIGHT</>');
        $this->line('  Playlist w pliku: '.count($zadania).', do zrobienia: '.count($todo));
        $this->line('  Catalogue before the start: '.number_format(CatalogTrack::count(), 0, ',', ' '));
        $this->newLine();

        $importer = CatalogImporter::make();
        $reserve = (int) $this->option('reserve');
        $gap = (int) $this->option('pause');

        $total = ['added' => 0, 'updated' => 0, 'units' => 0, 'playlist' => 0];

        foreach ($todo as $job) {
            $quota = QuotaGuard::fromConfig();

            if ($quota->remaining() <= $reserve) {
                $this->newLine();
                $this->warn('  Hit the reserve kept for the search - stopping.');
                $this->line('  Run it again after 9:00; the progress is saved.');
                break;
            }

            $this->line(sprintf(
                '  [%s] <fg=cyan>%s</>%s',
                now()->format('H:i:s'),
                $job['id'],
                $job['genre'] ? " ({$job['genre']})" : ''
            ));

            $stats = $importer->importPlaylist($job['id'], $job['genre']);

            $this->line(sprintf(
                '           dodane: <fg=green>%d</>  zaktualizowane: %d  pominiete: %d  koszt: %d j.',
                $stats['added'], $stats['updated'], $stats['skipped'], $stats['units']
            ));

            $total['added'] += $stats['added'];
            $total['updated'] += $stats['updated'];
            $total['units'] += $stats['units'];
            $total['playlist']++;

            $zrobione[] = $job['id'];
            $this->saveProgress($zrobione);

            if ($gap > 0) {
                sleep($gap);
            }
        }

        $this->newLine();
        $this->info('  Zaimportowano '.number_format($total['added'], 0, ',', ' ')
            .' nowych utworow z '.$total['playlist'].' playlist');
        $this->line('  Cost: '.$total['units'].' quota units');
        $this->line('  Catalogue after the import: <fg=green>'
            .number_format(CatalogTrack::count(), 0, ',', ' ').'</> utworow');
        $this->line('  Z czystym dzwiekiem studyjnym: '
            .number_format(CatalogTrack::where('is_topic', true)->count(), 0, ',', ' '));
        $this->newLine();

        return self::SUCCESS;
    }

    /** @return array<int, array{id: string, gatunek: ?string}> */
    private function readJobs(string $file): array
    {
        $lines = preg_split('/\R/', Storage::get($file)) ?: [];
        $zadania = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line, 2);

            $zadania[] = [
                'id' => $parts[0],
                'genre' => isset($parts[1]) ? trim($parts[1]) : null,
            ];
        }

        return $zadania;
    }

    private function readProgress(): array
    {
        return Storage::exists(self::STAN)
            ? (json_decode(Storage::get(self::STAN), true) ?: [])
            : [];
    }

    private function saveProgress(array $zrobione): void
    {
        Storage::put(self::STAN, json_encode(array_values(array_unique($zrobione))));
    }
}

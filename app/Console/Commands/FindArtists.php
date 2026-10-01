<?php

namespace App\Console\Commands;

use App\Models\ArtistChannel;
use App\Models\CatalogTrack;
use App\Services\CatalogImporter;
use App\Services\YouTube\QuotaGuard;
use Illuminate\Console\Command;

/**
 * Finds the channels of artists not yet in the catalogue.
 *
 * This is the most expensive operation in the system: 100 units per artist. With
 * 502 unknown artists that is about 50,000 units, several days of work - which
 * is why the command is built to run in stages.
 *
 * Each run:
 *   1. takes from the queue as many artists as the day's budget allows,
 *   2. finds their channels (100 units each),
 *   3. pulls in the whole discographies (1 unit per 50 tracks),
 *   4. records the state and stops.
 *
 * The next day, after the quota resets at 9 a.m., it is enough to run it again.
 */
class FindArtists extends Command
{
    protected $signature = 'catalog:find-artists
                            {--from-folder= : Fill the queue from a music folder}
                            {--per-run=0 : How many artists per run (0 = as many as the quota allows)}
                            {--limit=200 : Most tracks taken from one channel}
                            {--reserve=2500 : How many units to leave for guest searches}
                            {--pause=2 : Pause between artists, in seconds}
                            {--queue-only : Show the state of the queue and stop}';

    protected $description = 'Finds and imports the discographies of missing artists (in stages)';

    public function handle(): int
    {
        if ($folder = $this->option('from-folder')) {
            $this->fillQueue($folder);
        }

        $this->stanKolejki();

        if ($this->option('queue-only')) {
            return self::SUCCESS;
        }

        $reserve = (int) $this->option('reserve');
        $quota = QuotaGuard::fromConfig();
        $budget = max(0, $quota->remaining() - $reserve);
        $count = (int) $this->option('per-run') ?: intdiv($budget, QuotaGuard::COST['search']);

        if ($count < 1) {
            $this->warn('  No budget left today. The quota resets at 9:00.');

            return self::SUCCESS;
        }

        $todo = ArtistChannel::where('status', 'pending')->limit($count)->get();

        if ($todo->isEmpty()) {
            $this->info('  The queue is empty - every artist has been processed.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('  Budzet na dzis: <fg=cyan>'.number_format($budget, 0, ',', ' ')
            .'</> jednostek, czyli do <fg=cyan>'.$count.'</> wykonawcow');
        $this->line('  Taking from the queue: '.$todo->count());
        $this->newLine();

        $importer = CatalogImporter::make();
        $client = $importer->youtube();
        $gap = (int) $this->option('pause');
        $limit = (int) $this->option('limit');

        $total = ['found' => 0, 'tracks' => 0, 'units' => 0, 'none' => 0];

        foreach ($todo as $artistName) {
            if (QuotaGuard::fromConfig()->remaining() <= $reserve) {
                $this->newLine();
                $this->warn('  Osiagnieto rezerwe - przerywam. Reszta czeka w kolejce.');
                break;
            }

            $channel = $client->findArtistChannel($artistName->name);
            $cost = QuotaGuard::COST['search'];

            if (! $channel || empty($channel['channel_id'])) {
                $artistName->update([
                    'status' => 'none', 'searched_at' => now(), 'unit_cost' => $cost,
                ]);
                $this->line(sprintf('  [%s] %-30s <fg=red>not found</>',
                    now()->format('H:i:s'), mb_substr($artistName->name, 0, 30)));
                $total['none']++;
                $total['units'] += $cost;

                continue;
            }

            $playlisty = $client->uploadsPlaylists([$channel['channel_id']]);
            $cost += QuotaGuard::COST['videos'];

            $data = $playlisty[$channel['channel_id']] ?? null;
            $added = 0;

            if ($data) {
                $stats = $importer->importPlaylist($data['playlist'], null, true, $limit);
                $added = $stats['added'];
                $cost += $stats['units'];
            }

            $artistName->update([
                'channel_id' => $channel['channel_id'],
                'channel_title' => $channel['title'],
                'uploads_playlist' => $data['playlist'] ?? null,
                'status' => $data ? 'imported' : 'found',
                'imported_tracks' => $added,
                'unit_cost' => $cost,
                'searched_at' => now(),
            ]);

            $this->line(sprintf('  [%s] %-30s <fg=green>+%d</> <fg=gray>(%s, %d j.)</>',
                now()->format('H:i:s'),
                mb_substr($artistName->name, 0, 30),
                $added,
                mb_substr($channel['title'], 0, 24),
                $cost
            ));

            $total['found']++;
            $total['tracks'] += $added;
            $total['units'] += $cost;

            if ($gap > 0) {
                sleep($gap);
            }
        }

        $this->newLine();
        $this->info('  Found '.$total['found'].' artists, added '
            .number_format($total['tracks'], 0, ',', ' ').' utworow');
        $this->line('  No result: '.$total['none'].' | Cost: '.$total['units'].' units');
        $this->line('  Catalogue: <fg=green>'.number_format(CatalogTrack::count(), 0, ',', ' ').'</> tracks');
        $this->newLine();

        $zostalo = ArtistChannel::where('status', 'pending')->count();

        if ($zostalo > 0) {
            $this->line('  <fg=yellow>Still in the queue: '.$zostalo.' artists ('
                .ceil($zostalo / max(1, $count)).' dni).</>');
            $this->line('  <fg=gray>Uruchom ponownie po 9:00: php artisan catalog:find-artists</>');
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /** Queues the artists from the folder, skipping the ones already known. */
    private function fillQueue(string $folder): void
    {
        if (! is_dir($folder)) {
            $this->error('  There is no such folder: '.$folder);

            return;
        }

        $dodani = 0;
        $skipped = 0;

        foreach (scandir($folder) ?: [] as $row) {
            if ($row === '.' || $row === '..' || ! is_dir($folder.DIRECTORY_SEPARATOR.$row)) {
                continue;
            }

            $name = trim(str_replace('_', ' ', $row));
            $norm = ArtistChannel::norm($name);

            if ($norm === '' || ArtistChannel::where('name_norm', $norm)->exists()) {
                continue;
            }

            // The artist is already in the catalogue with a known channel - no need to search.
            $known = CatalogTrack::whereNotNull('channel_id')
                ->where(fn ($q) => $q->where('artist', 'like', '%'.$name.'%')
                    ->orWhere('channel', 'like', '%'.$name.'%'))
                ->exists();

            if ($known) {
                $skipped++;

                continue;
            }

            ArtistChannel::create(['name' => $name, 'name_norm' => $norm, 'status' => 'pending']);
            $dodani++;
        }

        $this->newLine();
        $this->line("  Added to the queue: <fg=cyan>{$dodani}</> artists");
        $this->line("  Skipped (already in the catalogue): {$skipped}");
    }

    private function stanKolejki(): void
    {
        $rows = [];

        foreach (['pending', 'imported', 'found', 'none'] as $status) {
            $count = ArtistChannel::where('status', $status)->count();

            if ($count > 0) {
                $rows[] = [$status, number_format($count, 0, ',', ' ')];
            }
        }

        if ($rows !== []) {
            $this->newLine();
            $this->table(['Stan', 'Ilu'], $rows);
        }
    }
}

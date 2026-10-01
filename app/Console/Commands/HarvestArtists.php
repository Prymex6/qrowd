<?php

namespace App\Console\Commands;

use App\Models\ArtistChannel;
use App\Models\CatalogTrack;
use App\Services\CatalogImporter;
use App\Services\YouTube\QuotaGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Extends the catalogue with artists' WHOLE discographies.
 *
 * Rather than collecting tracks one at a time from compilations, we take the
 * artist channels already in the catalogue and pull everything from them.
 *
 * By default the "- Topic" channels alone. Those are the automatic channels
 * YouTube creates for labels: they carry the clean audio from the record, with
 * no spoken intro, no dialogue from the music video and no outro announcement.
 * Exactly what a wedding wants - and the only way to avoid talked-over openings
 * without listening to every track by hand.
 *
 * Cost: 1 unit per 50 channels + 1 unit per 50 tracks.
 */
class HarvestArtists extends Command
{
    protected $signature = 'catalog:artists
                            {--all : Also widen channels other than "- Topic"}
                            {--limit=150 : Most tracks taken from one channel}
                            {--pause=2 : Pause between channels, in seconds}
                            {--reserve=3000 : How many units to leave for guest searches}
                            {--from-folder= : Widen only the artists present in this folder}
                            {--fresh : Ignore the saved progress}';

    protected $description = 'Pulls in the whole discographies of artists found in the catalogue';

    private const STAN = 'artysci-postep.json';

    public function handle(): int
    {
        $importer = CatalogImporter::make();
        $client = $importer->youtube();
        $reserve = (int) $this->option('reserve');
        $limit = (int) $this->option('limit');
        $gap = (int) $this->option('pause');

        $zrobione = $this->option('fresh') ? [] : $this->readProgress();

        $kanaly = CatalogTrack::query()
            ->whereNotNull('channel_id')
            ->when(! $this->option('all'), fn ($q) => $q->where('is_topic', true))
            ->whereNotIn('channel_id', $zrobione ?: ['-'])
            ->select('channel_id')
            ->selectRaw('MAX(channel) as name, MAX(artist) as artist, COUNT(*) as total')
            ->groupBy('channel_id')
            ->orderByDesc('total')
            ->get();

        // Narrowed to the artists the user actually listens to. A catalogue
        // built from random playlists does not match real taste, so the music
        // folder is a better signpost than popularity alone.
        if ($folder = $this->option('from-folder')) {
            $wanted = $this->wykonawcyZFolderu($folder);

            $this->line('  Artists in the folder: '.count($wanted));

            $kanaly = $kanaly->filter(function ($k) use ($wanted) {
                $name = ArtistChannel::norm((string) ($k->nazwa ?: $k->artist));
                $wyk = ArtistChannel::norm((string) $k->artist);

                foreach ($wanted as $s) {
                    if ($s === '') {
                        continue;
                    }
                    if (str_contains($name, $s) || str_contains($wyk, $s)
                        || str_contains($s, $wyk) && $wyk !== '') {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>WIDENING THE CATALOGUE WITH ARTISTS</>');
        $this->line('  Channels to walk: '.$kanaly->count()
            .($this->option('all') ? '' : ' ("- Topic" only, clean audio)'));
        $this->line('  Catalogue before the start: '.number_format(CatalogTrack::count(), 0, ',', ' '));
        $this->newLine();

        if ($kanaly->isEmpty()) {
            $this->warn('  No channels to process. Run this first: php artisan catalog:refresh');

            return self::SUCCESS;
        }

        $total = ['added' => 0, 'units' => 0, 'channels' => 0];

        foreach ($kanaly->chunk(50) as $batch) {
            if (QuotaGuard::fromConfig()->remaining() <= $reserve) {
                $this->newLine();
                $this->warn('  Hit the reserve kept for the search - stopping. Progress saved.');
                break;
            }

            $playlisty = $client->uploadsPlaylists($batch->pluck('channel_id')->all());
            $total['units'] += QuotaGuard::COST['videos'];

            foreach ($batch as $channel) {
                if (QuotaGuard::fromConfig()->remaining() <= $reserve) {
                    break 2;
                }

                $data = $playlisty[$channel->channel_id] ?? null;

                if (! $data) {
                    $zrobione[] = $channel->channel_id;

                    continue;
                }

                $name = $this->nazwaWykonawcy($data['name'] ?: $channel->nazwa);

                $stats = $importer->importPlaylist($data['playlist'], null, true, $limit);

                $this->line(sprintf(
                    '  [%s] %-38s <fg=green>+%d</> (koszt %d j.)',
                    now()->format('H:i:s'),
                    mb_substr($name, 0, 38),
                    $stats['added'],
                    $stats['units']
                ));

                $total['added'] += $stats['added'];
                $total['units'] += $stats['units'];
                $total['channels']++;

                $zrobione[] = $channel->channel_id;
                $this->saveProgress($zrobione);

                if ($gap > 0) {
                    sleep($gap);
                }
            }
        }

        $this->newLine();
        $this->info('  Added '.number_format($total['added'], 0, ',', ' ')
            .' utworow z '.$total['channels'].' kanalow');
        $this->line('  Cost: '.$total['units'].' units');
        $this->line('  Catalogue: <fg=green>'.number_format(CatalogTrack::count(), 0, ',', ' ').'</> tracks');
        $this->line('  Z czystym dzwiekiem: '
            .number_format(CatalogTrack::where('is_topic', true)->count(), 0, ',', ' '));
        $this->newLine();

        return self::SUCCESS;
    }

    /** @return array<int, string> znormalizowane nazwy wykonawcow z folderu */
    private function wykonawcyZFolderu(string $folder): array
    {
        if (! is_dir($folder)) {
            $this->warn('  There is no such folder: '.$folder);

            return [];
        }

        $nazwy = [];

        foreach (scandir($folder) ?: [] as $row) {
            if ($row === '.' || $row === '..' || ! is_dir($folder.DIRECTORY_SEPARATOR.$row)) {
                continue;
            }

            $n = ArtistChannel::norm(str_replace('_', ' ', $row));

            if ($n !== '') {
                $nazwy[] = $n;
            }
        }

        return array_unique($nazwy);
    }

    private function nazwaWykonawcy(string $channel): string
    {
        return trim(preg_replace('/\s*-\s*Topic\s*$/iu', '', $channel));
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

<?php

namespace App\Console\Commands;

use App\Models\CatalogTrack;
use App\Services\CatalogImporter;
use App\Services\YouTube\QuotaGuard;
use Illuminate\Console\Command;

/**
 * Uzupelnia metadane w utworach zaimportowanych zanim powstaly nowe kolumny:
 * dokladny tytul, kanal, identyfikator kanalu, miniature i znacznik czystego
 * dzwieku studyjnego.
 *
 * Kosztuje 1 jednostke na 50 utworow, wiec odswiezenie calego katalogu
 * to ulamek dobowego limitu.
 */
class RefreshCatalogMetadata extends Command
{
    protected $signature = 'catalog:refresh
                            {--all : Also refresh tracks that already have metadata}
                            {--reserve=3000 : How many units to leave for guest searches}';

    protected $description = 'Fills in the missing metadata of tracks in the catalogue';

    public function handle(): int
    {
        $importer = CatalogImporter::make();
        $client = $importer->youtube();
        $reserve = (int) $this->option('reserve');

        // By default we take only what is missing - that way an interrupted
        // import resumes where it stopped instead of paying quota for tracks
        // already filled in. We take the gaps, but ONLY those we have not checked
        // for a long time.
        //
        // Some videos have no public statistics - the author hid them. For those
        // view_count will stay empty forever, so without a condition on
        // checked_at the command took the same tracks on every run and paid quota
        // for them endlessly, never finishing the job.
        $query = CatalogTrack::query()
            ->when(! $this->option('all'), fn ($q) => $q
                ->where(fn ($w) => $w->whereNull('channel_id')->orWhereNull('view_count'))
                ->where(fn ($w) => $w->whereNull('checked_at')
                    ->orWhere('checked_at', '<', now()->subDays(30)))
            );

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('  Wszystkie utwory maja komplet metadanych.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('  To fill in: <fg=cyan>'.number_format($count, 0, ',', ' ').'</> tracks');
        $this->line('  Estimated cost: '.(int) ceil($count / 50).' units');
        $this->newLine();

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $updated = 0;
        $interrupted = false;

        $query->select('id', 'youtube_id')->chunkById(50, function ($batch) use (
            $client, $importer, &$updated, $bar, $reserve, &$interrupted
        ) {
            if (QuotaGuard::fromConfig()->remaining() <= $reserve) {
                $interrupted = true;

                return false;
            }

            $fresh = $client->videos($batch->pluck('youtube_id')->all());

            foreach ($fresh as $track) {
                $updated += $importer->store([$track])['updated'];
            }

            $bar->advance($batch->count());
        });

        $bar->finish();
        $this->newLine(2);

        if ($interrupted) {
            $this->warn('  Stopped - hit the quota reserve. Run it again after 9:00.');
        }

        $this->info('  Filled in: '.number_format($updated, 0, ',', ' ').' tracks');
        $this->line('  Z czystym dzwiekiem studyjnym: '
            .number_format(CatalogTrack::where('is_topic', true)->count(), 0, ',', ' '));
        $this->line('  Known artist channels: '
            .number_format(CatalogTrack::whereNotNull('channel_id')->distinct('channel_id')->count('channel_id'), 0, ',', ' '));
        $this->newLine();

        return self::SUCCESS;
    }
}

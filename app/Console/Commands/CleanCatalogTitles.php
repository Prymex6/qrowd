<?php

namespace App\Console\Commands;

use App\Models\CatalogTrack;
use App\Support\TrackTitle;
use Illuminate\Console\Command;

class CleanCatalogTitles extends Command
{
    protected $signature = 'catalog:clean {--dry-run : Pokaz zmiany, nie zapisuj}';

    protected $description = 'Tidies titles and artists in the catalogue (strips the YouTube additions)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changes = 0;
        $examples = [];

        $this->newLine();
        $this->line('  Tidying '.number_format(CatalogTrack::count()).' tracks...');

        CatalogTrack::chunkById(500, function ($tracks) use (&$changes, &$examples, $dry) {
            foreach ($tracks as $track) {
                // Odtwarzamy pelny tytul i dzielimy go od nowa.
                $raw = $track->artist ? "{$track->artist} - {$track->title}" : $track->title;

                [$artist, $title] = TrackTitle::split($raw, null);

                if ($title === '') {
                    continue;
                }

                if ($artist !== $track->artist || $title !== $track->title) {
                    if (count($examples) < 12) {
                        $examples[] = [
                            mb_substr($raw, 0, 58),
                            mb_substr(($artist ? $artist.' - ' : '').$title, 0, 58),
                        ];
                    }

                    if (! $dry) {
                        $track->update(['artist' => $artist, 'title' => $title]);
                    }

                    $changes++;
                }
            }
        });

        $this->newLine();
        $this->table(['Bylo', 'Jest'], $examples);
        $this->newLine();

        $this->info('  '.($dry ? 'To correct: ' : 'Corrected: ').number_format($changes).' tracks');
        $this->newLine();

        return self::SUCCESS;
    }
}

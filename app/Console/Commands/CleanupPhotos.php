<?php

namespace App\Console\Commands;

use App\Models\Party;
use App\Models\Photo;
use Illuminate\Console\Command;

/**
 * Deletes photos once the time the host set has passed.
 *
 * This is not disk tidying for convenience. Photos from a party are the likeness
 * of particular, recognisable people - that is, personal data. We promise in the
 * settings that after a set time they will be gone, so they have to be gone in
 * fact. Without this job the promise would be empty and the data would sit with
 * us indefinitely.
 */
class CleanupPhotos extends Command
{
    protected $signature = 'photos:cleanup
                            {--dry-run : Pokaż, co zostałoby usunięte, ale nic nie kasuj}';

    protected $description = 'Deletes party photos once the retention period has passed';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>TIDYING THE PHOTOS</>');

        $removed = 0;
        $megabajty = 0;
        $imprez = 0;
        $rows = [];

        Party::has('photos')->with('photos')->chunkById(50, function ($parties) use (
            &$removed, &$megabajty, &$imprez, &$rows, $dry
        ) {
            foreach ($parties as $party) {
                $dni = $party->settings()->int('photo_retention_days');

                if ($dni <= 0) {
                    continue;
                }

                // Counted from the end of the party. A party with no end date
                // (abandoned, never started) counts from when it was created -
                // otherwise its photos would stay forever.
                $od = $party->ended_at ?? $party->ends_at ?? $party->starts_at ?? $party->created_at;
                $termin = $od->copy()->addDays($dni);

                if (now()->lt($termin)) {
                    continue;
                }

                $photos = $party->photos;

                if ($photos->isEmpty()) {
                    continue;
                }

                $rows[] = [
                    $party->code,
                    mb_substr($party->name, 0, 26),
                    $photos->count(),
                    round($photos->sum('size') / 1048576, 1).' MB',
                    $termin->format('d.m.Y'),
                ];

                $removed += $photos->count();
                $megabajty += $photos->sum('size') / 1048576;
                $imprez++;

                if (! $dry) {
                    foreach ($photos as $photo) {
                        $photo->deleteWithFiles();
                    }
                }
            }
        });

        $this->newLine();

        if ($rows === []) {
            $this->info('  Nothing to delete - no party has passed its retention period.');
            $this->line('  <fg=gray>Photos in the database: '.number_format(Photo::count(), 0, ',', ' ').'</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->table(['Kod', 'Impreza', 'Zdjęć', 'Rozmiar', 'Termin minął'], $rows);
        $this->newLine();

        $this->info(sprintf(
            '  %s %s zdjęć z %d imprez (%s MB)',
            $dry ? 'Do usunięcia:' : 'Usunięto:',
            number_format($removed, 0, ',', ' '),
            $imprez,
            number_format($megabajty, 1, ',', ' ')
        ));

        if ($dry) {
            $this->line('  <fg=gray>Run it without --dry-run to actually delete them.</>');
        }

        $this->newLine();

        return self::SUCCESS;
    }
}

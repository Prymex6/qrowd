<?php

namespace App\Console\Commands;

use App\Models\CatalogTrack;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills in the genre of tracks pulled from artists' discographies.
 *
 * The genre comes from the playlist, so tracks pulled in by catalog:artists
 * arrive without one - and those make up most of the catalogue. The effect is
 * that the genre filters in a party's settings ("no disco polo") cover a
 * fraction of the database while the host believes they work.
 *
 * We infer from the channel: if a Drake track reached us from a rap playlist,
 * then his whole discography from that same channel is rap too.
 */
class AssignGenres extends Command
{
    protected $signature = 'catalog:genres
                            {--dry-run : Pokaz, co by sie zmienilo, ale nie zapisuj}
                            {--min=1 : Ile utworow z gatunkiem musi miec kanal, zeby mu zaufac}';

    protected $description = 'Assigns genres to tracks by the channel of their artist';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $min = max(1, (int) $this->option('min'));

        $bezGatunku = CatalogTrack::whereNull('genre')->count();

        $this->newLine();
        $this->line('  Tracks with no genre: <fg=yellow>'.number_format($bezGatunku, 0, ',', ' ').'</>');

        if ($bezGatunku === 0) {
            $this->info('  There is nothing to fill in.');

            return self::SUCCESS;
        }

        // For each channel we settle on the dominant genre - the one that appears
        // most often among the tracks already labelled.
        $dominant = DB::table('catalog_tracks')
            ->select('channel_id', 'genre', DB::raw('COUNT(*) as total'))
            ->whereNotNull('channel_id')
            ->whereNotNull('genre')
            ->groupBy('channel_id', 'genre')
            ->orderByDesc('total')
            ->get()
            ->groupBy('channel_id')
            ->map(fn ($rows) => $rows->first())
            ->filter(fn ($w) => $w->total >= $min);

        $this->line('  Channels with a settled genre: '.number_format($dominant->count(), 0, ',', ' '));
        $this->newLine();

        $changed = 0;
        $sample = [];

        foreach ($dominant as $channelId => $data) {
            $count = CatalogTrack::where('channel_id', $channelId)->whereNull('genre')->count();

            if ($count === 0) {
                continue;
            }

            if (count($sample) < 15) {
                $name = CatalogTrack::where('channel_id', $channelId)->value('channel');
                $sample[] = [
                    mb_substr(preg_replace('/\s*-\s*Topic\s*$/iu', '', (string) $name), 0, 34),
                    $data->genre,
                    number_format($count, 0, ',', ' '),
                ];
            }

            if (! $dry) {
                CatalogTrack::where('channel_id', $channelId)
                    ->whereNull('genre')
                    ->update(['genre' => $data->genre]);
            }

            $changed += $count;
        }

        $this->table(['Wykonawca', 'Gatunek', 'Utworow'], $sample);
        $this->newLine();

        $this->info('  '.($dry ? 'Do przypisania: ' : 'Przypisano gatunek: ')
            .number_format($changed, 0, ',', ' ').' utworom');

        $zostalo = CatalogTrack::whereNull('genre')->count() - ($dry ? 0 : 0);
        $this->line('  Still without a genre: '.number_format(
            $dry ? $bezGatunku : CatalogTrack::whereNull('genre')->count(), 0, ',', ' '
        ).' <fg=gray>(kanaly, ktore nigdy nie trafily do zadnej playlisty)</>');

        if (! $dry) {
            $this->newLine();
            $this->line('  <fg=cyan>Rozklad gatunkow po zmianie:</>');

            foreach (CatalogTrack::selectRaw('genre, COUNT(*) as total')
                ->whereNotNull('genre')->groupBy('genre')->orderByDesc('total')->get() as $g) {
                $this->line(sprintf('    %-22s %s', $g->genre, number_format($g->total, 0, ',', ' ')));
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\CatalogTrack;
use App\Models\SearchCache;
use App\Services\YouTube\QuotaGuard;
use Illuminate\Console\Command;

class CatalogStatus extends Command
{
    protected $signature = 'catalog:status';

    protected $description = 'Shows the state of the track catalogue and the YouTube quota used';

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>THE TRACK CATALOGUE</>');

        $total = CatalogTrack::count();
        $this->line('  Tracks in the database: <fg=green>'.number_format($total).'</>');
        $this->line('  Zdatnych do grania: '.number_format(CatalogTrack::playable()->count()));
        $this->line('  Nieosadzalnych: '.number_format(CatalogTrack::where('is_embeddable', false)->count()));

        $genres = CatalogTrack::selectRaw('genre, COUNT(*) as total')
            ->whereNotNull('genre')->groupBy('genre')->orderByDesc('total')->get();

        if ($genres->isNotEmpty()) {
            $this->newLine();
            $this->line('  <fg=cyan>Gatunki</>');
            foreach ($genres as $g) {
                $this->line(sprintf('    %-20s %s', $g->genre, number_format($g->total)));
            }
        }

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>CACHE WYSZUKIWAN</>');
        $this->line('  Zapisanych zapytan: '.number_format(SearchCache::count()));
        $this->line('  Zaoszczedzonych wywolan: <fg=green>'.number_format((int) SearchCache::sum('hits')).'</>');
        $this->line('  That is, units saved: <fg=green>'
            .number_format((int) SearchCache::sum('hits') * QuotaGuard::COST['search']).'</>');

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>LIMIT YOUTUBE</>');

        foreach (QuotaGuard::fromConfig()->status() as $key => $value) {
            $this->line(sprintf('  %-14s %s', $key.':', is_bool($value) ? ($value ? 'YES' : 'no') : $value));
        }

        $this->newLine();

        if ($total < 500) {
            $this->warn('  The catalogue is small. Import playlists: php artisan catalog:import <PLAYLIST_ID>');
            $this->newLine();
        }

        return self::SUCCESS;
    }
}

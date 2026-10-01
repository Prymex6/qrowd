<?php

namespace App\Console\Commands;

use App\Models\CatalogTrack;
use Illuminate\Console\Command;

/**
 * Compares a music folder on disk with our catalogue.
 *
 * File names never match YouTube titles character for character - different
 * diacritics, additions like "(feat. X)" or "Official Video", a different order
 * of artists. So we compare normalised forms: no diacritics, no punctuation, no
 * additions.
 */
class CheckMusicFolder extends Command
{
    protected $signature = 'catalog:check-folder
                            {path : Path to the music folder}
                            {--missing= : Write the missing tracks to this file}
                            {--show=25 : How many missing tracks to print}';

    protected $description = 'Checks which tracks from a folder are already in the catalogue';

    /** Polskie znaki i typowe europejskie - iconv na Windowsie bywa zawodny. */
    private const DIACRITICS = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'å' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
        'š' => 's', 'ž' => 'z', 'č' => 'c', 'ř' => 'r', 'ě' => 'e', 'ů' => 'u', 'ď' => 'd', 'ť' => 't', 'ň' => 'n',
    ];

    public function handle(): int
    {
        $path = rtrim($this->argument('path'), '\\/');

        if (! is_dir($path)) {
            $this->error("  There is no such folder: {$path}");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Skanuje: <fg=cyan>'.$path.'</>');

        $files = $this->findFiles($path);
        $this->line('  Audio files: <fg=cyan>'.number_format(count($files), 0, ',', ' ').'</>');

        if ($files === []) {
            return self::SUCCESS;
        }

        $this->line('  Building the catalogue index...');
        [$byPair, $poTytule] = $this->indeksKatalogu();
        $this->line('  Tracks in the catalogue: <fg=cyan>'.number_format(CatalogTrack::count(), 0, ',', ' ').'</>');
        $this->newLine();

        $sa = [];
        $brak = [];

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $file) {
            [$artistName, $title] = $this->splitName($file);

            $pairKey = $this->norm($artistName).'|'.$this->norm($title);
            $titleKey = $this->norm($title);

            if (isset($byPair[$pairKey])) {
                $sa[] = [$artistName, $title, 'pair'];
            } elseif ($this->pasujePoTytule($poTytule, $titleKey, $artistName)) {
                $sa[] = [$artistName, $title, 'title'];
            } else {
                $brak[] = ['artist' => $artistName, 'title' => $title, 'file' => $file];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->raport($files, $sa, $brak);

        if ($outputFile = $this->option('missing')) {
            $this->saveMissing($outputFile, $brak);
        }

        return self::SUCCESS;
    }

    // ------------------------------------------------------------ skanowanie

    private function findFiles(string $path): array
    {
        $extensions = ['mp3', 'm4a', 'flac', 'wav', 'ogg', 'aac', 'wma', 'opus'];
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $files[] = $file->getBasename('.'.$file->getExtension());
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Rozbija "Wykonawca, Drugi - Tytul (feat. X)" na wykonawce i tytul.
     * Bierzemy pierwszego wykonawcy - to on decyduje o dopasowaniu.
     */
    private function splitName(string $name): array
    {
        foreach ([' - ', ' – ', ' — '] as $sep) {
            if (str_contains($name, $sep)) {
                [$lewa, $prawa] = explode($sep, $name, 2);

                $artistName = trim(explode(',', $lewa)[0]);

                return [$artistName, trim($prawa)];
            }
        }

        return ['', trim($name)];
    }

    // ------------------------------------------------------------ indeks

    /** @return array{0: array<string,bool>, 1: array<string, array<int,string>>} */
    private function indeksKatalogu(): array
    {
        $byPair = [];
        $poTytule = [];

        CatalogTrack::select('title', 'artist')->chunk(2000, function ($batch) use (&$byPair, &$poTytule) {
            foreach ($batch as $t) {
                $title = $this->norm($t->title);
                $wyk = $this->norm($t->artist);

                if ($title === '') {
                    continue;
                }

                $byPair[$wyk.'|'.$title] = true;
                $poTytule[$title][] = $wyk;
            }
        });

        return [$byPair, $poTytule];
    }

    /**
     * The fallback match: the same title and an artist contained in the other.
     * It catches cases like "Boys" against "Boys Official", or the parts in
     * reversed order.
     */
    private function pasujePoTytule(array $poTytule, string $title, string $artistName): bool
    {
        if (! isset($poTytule[$title])) {
            return false;
        }

        $wanted = $this->norm($artistName);

        if ($wanted === '') {
            return true;
        }

        foreach ($poTytule[$title] as $kandydat) {
            if ($kandydat === '') {
                continue;
            }

            if (str_contains($kandydat, $wanted) || str_contains($wanted, $kandydat)) {
                return true;
            }
        }

        return false;
    }

    /** Do porownan: male litery, bez ogonkow, bez dopiskow i interpunkcji. */
    private function norm(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $t = mb_strtolower($text, 'UTF-8');
        $t = strtr($t, self::DIACRITICS);

        // Additions that do not change which track it is.
        $t = preg_replace('/\((feat|ft|with|prod)[^)]*\)/u', ' ', $t) ?? $t;
        $t = preg_replace('/\b(official|video|audio|teledysk|lyrics?|hd|4k|remaster(ed)?)\b/u', ' ', $t) ?? $t;

        $t = preg_replace('/[^a-z0-9 ]/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;

        return trim($t);
    }

    // ------------------------------------------------------------ raport

    private function raport(array $files, array $sa, array $brak): void
    {
        $all = count($files);
        $jest = count($sa);
        $missing = count($brak);
        $procent = $all > 0 ? round($jest / $all * 100, 1) : 0;

        $this->line('  <fg=green;options=bold>IN THE CATALOGUE:</>  '
            .number_format($jest, 0, ',', ' ').' z '.number_format($all, 0, ',', ' ')
            ."  ({$procent}%)");
        $this->line('  <fg=yellow;options=bold>BRAKUJE:</>         '
            .number_format($missing, 0, ',', ' '));
        $this->newLine();

        $byPair = count(array_filter($sa, fn ($s) => $s[2] === 'pair'));
        $this->line('  <fg=gray>Matched by artist and title: '.number_format($byPair, 0, ',', ' ')
            .', po samym tytule: '.number_format($jest - $byPair, 0, ',', ' ').'</>');
        $this->newLine();

        if ($brak === []) {
            $this->info('  Every track from the folder is already in the catalogue.');

            return;
        }

        $count = (int) $this->option('show');

        $this->line('  <fg=yellow>Przykladowe brakujace:</>');
        $this->table(
            ['Wykonawca', 'Tytul'],
            collect($brak)->take($count)->map(fn ($b) => [
                mb_substr($b['artist'], 0, 30),
                mb_substr($b['title'], 0, 46),
            ])->all()
        );

        if ($missing > $count) {
            $this->line('  <fg=gray>...i jeszcze '.number_format($missing - $count, 0, ',', ' ').'</>');
        }

        $this->newLine();
    }

    private function saveMissing(string $file, array $brak): void
    {
        $lines = array_map(
            fn ($b) => $b['artist'] !== '' ? "{$b['artist']} - {$b['title']}" : $b['title'],
            $brak
        );

        file_put_contents($file, implode(PHP_EOL, $lines).PHP_EOL);

        $this->info('  Brakujace zapisane do: '.$file);
        $this->newLine();
    }
}

<?php

namespace App\Support;

/**
 * Tidying titles that come from YouTube.
 *
 * A raw YouTube title looks like this:
 *   "BOYS - Wakacyjna milosc (Nowy Oficjalny Teledysk) HIT Disco Polo 2024 [4K]"
 *
 * A guest at a wedding wants to see:
 *   Boys - Wakacyjna milosc
 *
 * Without this step the search looks like a rubbish tip, and the split into
 * artist and title comes out at random.
 */
class TrackTitle
{
    /** The additions that add nothing and always go in the bin. */
    private const NOISE = [
        'official video', 'official audio', 'official music video', 'official lyric video',
        'oficjalny teledysk', 'oficjalne video', 'oficjalny videoclip', 'teledysk',
        'nowy oficjalny teledysk', 'audio', 'lyrics', 'lyric video', 'text',
        'premiera', 'nowosc', 'nowość', 'hit', 'hity', 'przeboj', 'przebój',
        'video', 'videoclip', 'clip', 'cover', 'wersja radiowa', 'radio edit',
        'disco polo', 'nowe disco polo', 'najlepsze disco polo',
        '4k', 'hd', 'full hd', 'remaster', 'remastered', 'hq',
    ];

    /**
     * @return array{0: ?string, 1: string} [wykonawca, tytul]
     */
    public static function split(string $rawTitle, ?string $channel = null): array
    {
        $title = self::clean($rawTitle);

        foreach ([' - ', ' – ', ' — ', ' | '] as $separator) {
            if (! str_contains($title, $separator)) {
                continue;
            }

            [$left, $right] = array_map('trim', explode($separator, $title, 2));

            if ($left === '' || $right === '') {
                continue;
            }

            // The order "Artist - Title" is the YouTube convention and the vast
            // majority of channels keep to it. I tried guessing at a reversal from
            // the length of the parts, but that broke correct entries with long
            // artist names (Meskie Granie Orkiestra 2020, say) - better to trust
            // the convention.
            return [self::tidyName($left), self::tidyName($right)];
        }

        return [$channel ? self::tidyName(self::cleanChannel($channel)) : null, self::tidyName($title)];
    }

    /** Usuwa nawiasy z dopiskami, roczniki, emoji i nadmiarowe spacje. */
    public static function clean(string $title): string
    {
        // We keep letters, digits, spaces and ordinary punctuation. Everything
        // else - emoji, frames, arrows, channel decorations - goes in the bin.
        // Listing particular characters is pointless, because channels keep
        // inventing new ones.
        $title = preg_replace('/[^\p{L}\p{N}\p{Zs}\.,!\?\x27"&\(\)\[\]\{\}\-–—\/|:+#]+/u', ' ', $title) ?? $title;

        // "Wykonawca - Topic" to automatyczne kanaly YouTube Music.
        $title = preg_replace('/\s*-\s*Topic\s*(-\s*)?/iu', ' - ', $title) ?? $title;

        // Nawiasy zawierajace wylacznie szum - reszte nawiasow zostawiamy
        // (np. "(Fair Play Remix)" niesie informacje).
        $title = preg_replace_callback('/[\(\[\{]([^\)\]\}]*)[\)\]\}]/u', function ($m) {
            $inside = mb_strtolower(trim($m[1]));

            if ($inside === '' || preg_match('/^\d{4}$/', $inside)) {
                return ' ';
            }

            foreach (self::NOISE as $noise) {
                if (str_contains($inside, $noise)) {
                    return ' ';
                }
            }

            return $m[0];
        }, $title) ?? $title;

        // Ogony bez nawiasow: "... HIT Disco Polo 2024"
        $pattern = '/\s+('.implode('|', array_map('preg_quote', self::NOISE)).')(\s+\d{4})?\s*$/iu';
        for ($i = 0; $i < 4; $i++) {
            $new = preg_replace($pattern, '', $title) ?? $title;
            if ($new === $title) {
                break;
            }
            $title = $new;
        }

        // Channels insert decorations that stopped the noisy tail being caught.
        $title = preg_replace('/[\x{2758}\x{25B6}\x{2022}\x{00A6}\x{2503}\x{00B7}]+/u', ' ', $title) ?? $title;
        $title = preg_replace($pattern, '', $title) ?? $title;
        $title = self::dropNoisyTail($title);
        $title = preg_replace('/\s*[\|\/\-–—]\s*$/u', '', $title) ?? $title;
        $title = preg_replace('/\s{2,}/u', ' ', $title) ?? $title;

        return trim($title, " \t\n\r\0\x0B-–—|/");
    }

    /**
     * The tail after a vertical bar is usually channel advertising rather than
     * part of the title:
     *   "Kocham Cie Za Bardzo | NOWOSC 2026 HIT DISCO POLO"
     * We cut it off, but only when it really consists of such additions -
     * otherwise we would wipe sensible titles that contain a bar.
     */
    private static function dropNoisyTail(string $title): string
    {
        if (! str_contains($title, '|')) {
            return $title;
        }

        $parts = array_map('trim', explode('|', $title));
        $head = array_shift($parts);

        if ($head === '' || mb_strlen($head) < 4) {
            return $title;
        }

        foreach ($parts as $part) {
            $lower = mb_strtolower($part);
            $isNoise = false;

            foreach (self::NOISE as $noise) {
                if (str_contains($lower, $noise)) {
                    $isNoise = true;
                    break;
                }
            }

            // A part with no such additions may belong to the title - then we leave it.
            if (! $isNoise && ! preg_match('/^\d{4}$/', trim($lower))) {
                return $title;
            }
        }

        return $head;
    }

    /** Kanaly typu "Zenek Martyniuk - Topic" albo "BoysOfficial". */
    public static function cleanChannel(string $channel): string
    {
        $channel = preg_replace('/\s*-\s*Topic\s*$/iu', '', $channel) ?? $channel;
        $channel = preg_replace('/\s*(VEVO|Official|Oficjalny)\s*$/iu', '', $channel) ?? $channel;

        return trim($channel);
    }

    /** ZENEK MARTYNIUK -> Zenek Martyniuk. Krotkie skroty zostawiamy wielkimi. */
    public static function tidyName(string $text): string
    {
        $text = preg_replace('/\s*-\s*Topic\s*$/iu', '', $text) ?? $text;
        $text = trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);

        if ($text === '' || mb_strtoupper($text) !== $text) {
            return $text;
        }

        if (mb_strlen($text) <= 4) {
            return $text;
        }

        return mb_convert_case(mb_strtolower($text), MB_CASE_TITLE, 'UTF-8');
    }
}

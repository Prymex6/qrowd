<?php

namespace App\Services;

use App\Models\CatalogTrack;
use App\Models\Party;
use App\Services\YouTube\QuotaGuard;
use Illuminate\Support\Facades\Http;

/**
 * The readiness check, thirty minutes before the party.
 *
 * The reason this class exists is simple: without it you learn about a problem
 * when a hundred people are already in the room and the bride is looking at you.
 * Every check answers a real failure seen in accounts of systems like this one.
 */
class PartyReadiness
{
    public function __construct(private Party $party) {}

    public static function for(Party $party): self
    {
        return new self($party);
    }

    /** @return array{punkty: array, gotowych: int, wszystkich: int, mozna_startowac: bool} */
    public function check(): array
    {
        $checks = [
            $this->internet(),
            $this->player(),
            $this->catalog(),
            $this->quota(),
            $this->sound(),
            $this->ads(),
            $this->volume(),
            $this->schedule(),
        ];

        $checks = array_values(array_filter($checks));

        $readyCount = count(array_filter($checks, fn ($p) => $p['status'] === 'ok'));
        $bledy = array_filter($checks, fn ($p) => $p['status'] === 'error');

        return [
            'checks' => $checks,
            'gotowych' => $readyCount,
            'total' => count($checks),
            'can_start' => count($bledy) === 0,
        ];
    }

    // ------------------------------------------------------------ punkty

    private function internet(): array
    {
        try {
            $start = microtime(true);
            $ok = Http::timeout(4)->head('https://www.youtube.com')->successful();
            $ms = (int) ((microtime(true) - $start) * 1000);
        } catch (\Throwable $e) {
            $ok = false;
            $ms = 0;
        }

        return [
            'key' => 'internet',
            'name' => 'Połączenie z internetem',
            'status' => $ok ? 'ok' : 'error',
            'description' => $ok
                ? "Serwer widzi YouTube (odpowiedź w {$ms} ms)"
                : 'Serwer nie może połączyć się z YouTube. Bez tego nic nie zagra.',
            'action' => null,
        ];
    }

    private function player(): array
    {
        $seenAt = $this->party->player_seen_at;
        $ok = $seenAt && $seenAt->gt(now()->subMinutes(10));

        return [
            'key' => 'player',
            'name' => 'Urządzenie grające połączone',
            'status' => $ok ? 'ok' : 'error',
            'description' => $ok
                ? 'Odtwarzacz odezwał się '.$seenAt->diffForHumans()
                : 'Otwórz poniższy link na laptopie podpiętym do nagłośnienia i zostaw tę kartę otwartą. Potem wróć tutaj i odśwież sprawdzenie.',
            'action' => $ok ? null : ['name' => 'Otwórz odtwarzacz', 'link' => url('/player/'.$this->party->code.'?token='.$this->party->player_token)],
        ];
    }

    private function catalog(): array
    {
        $count = CatalogTrack::playable()->count();
        $ok = $count >= 500;

        return [
            'key' => 'catalogue',
            'name' => 'Katalog utworów',
            'status' => $ok ? 'ok' : 'warning',
            'description' => number_format($count, 0, ',', ' ').' utworów gotowych do grania'
                .($ok ? '' : ' — mało, goście będą częściej sięgać po YouTube'),
            'action' => null,
        ];
    }

    private function quota(): array
    {
        $q = QuotaGuard::fromConfig();
        $searches = intdiv($q->remaining(), QuotaGuard::COST['search']);

        $status = match (true) {
            $searches >= 40 => 'ok',
            $searches >= 10 => 'warning',
            default => 'error',
        };

        return [
            'key' => 'limit',
            'name' => 'Limit wyszukiwań YouTube',
            'status' => $status,
            'description' => "Zostało {$searches} wyszukiwań na dziś (reset o 9:00). "
                .($status === 'ok'
                    ? 'Podpowiedzi z katalogu są darmowe i działają bez limitu.'
                    : 'Goście znajdą wszystko z katalogu, ale rzadkich kawałków mogą nie doszukać.'),
            'action' => null,
        ];
    }

    private function sound(): array
    {
        $when = $this->party->settings()->get('test_sound_at');
        $ok = $when && now()->parse($when)->gt(now()->subHours(6));

        return [
            'key' => 'audio',
            'name' => 'Test dźwięku',
            'status' => $ok ? 'ok' : 'warning',
            'description' => $ok
                ? 'Potwierdzony '.now()->parse($when)->diffForHumans()
                : 'Puść cokolwiek na odtwarzaczu i sprawdź, czy słychać z kolumn.',
            'action' => ['name' => 'Potwierdzam, słychać', 'confirm' => 'audio'],
        ];
    }

    private function ads(): array
    {
        $stan = $this->party->settings()->get('test_ads');

        return [
            'key' => 'reklamy',
            'name' => 'Reklamy YouTube',
            'status' => match ($stan) {
                'none' => 'ok',
                'present' => 'warning',
                default => 'warning',
            },
            'description' => match ($stan) {
                'none' => 'Potwierdzone — odtwarzacz gra bez reklam.',
                'present' => 'Wykryto reklamy. Zaloguj urządzenie grające na konto z YouTube Premium — miesiąc próbny jest za darmo, a wesele trwa jeden wieczór.',
                default => 'Odtwórz kawałek i sprawdź, czy wskoczyła reklama. Na weselu reklama w środku pierwszego tańca to koniec.',
            },
            'action' => ['name' => 'Nie ma reklam', 'confirm' => 'ads_none', 'alternatywa' => ['name' => 'Są reklamy', 'confirm' => 'ads_present']],
        ];
    }

    /**
     * The transitions between tracks.
     *
     * This used to be "volume levelling", driven by the normalize_volume setting
     * - a checkbox that did NOTHING but steer this very message. Real
     * normalisation cannot be done with an embedded YouTube frame: the sound
     * comes from another domain and the browser allows neither measuring nor
     * correcting it. YouTube normalises on its own side anyway, so the worst
     * jumps are already gone.
     *
     * What remains is what we DO control: crossfading. A loud track does not come
     * in at full level from the first second but climbs - and that jump is
     * exactly what sent people reaching for the knob.
     */
    private function volume(): array
    {
        $seconds = $this->party->settings()->int('crossfade_seconds');
        $ok = $seconds > 0;

        return [
            'key' => 'volume',
            'name' => 'Przejścia między utworami',
            'status' => $ok ? 'ok' : 'warning',
            'description' => $ok
                ? "Przenikanie {$seconds} s — głośny kawałek wchodzi z podbiciem, a nie z hukiem."
                : 'Przenikanie wyłączone. Utwory przechodzą ostrym cięciem i głośniejszy uderzy od razu.',
            'action' => null,
        ];
    }

    /** Weddings only - there the schedule is part of the product. */
    private function schedule(): ?array
    {
        if ($this->party->type !== 'wedding') {
            return null;
        }

        $count = $this->party->scheduleItems()->count();

        return [
            'key' => 'schedule',
            'name' => 'Harmonogram wesela',
            'status' => $count > 0 ? 'ok' : 'warning',
            'description' => $count > 0
                ? "{$count} punktów — pierwszy taniec i tort zagrają się same"
                : 'Pusty. Bez niego pierwszy taniec i tort musisz odpalić ręcznie.',
            'action' => $count > 0 ? null : ['name' => 'Ustaw harmonogram', 'link' => route('host.schedule', $this->party->code)],
        ];
    }
}

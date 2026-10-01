<?php

namespace Tests\Unit;

use App\Services\RankingEngine;
use App\Support\PartySettings;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the ranking engine. Pure arithmetic, no database - this is the one
 * part of the system that MUST be beyond question, because the order of play for
 * the whole evening depends on it.
 */
class RankingEngineTest extends TestCase
{
    private function engine(array $overrides = []): RankingEngine
    {
        return new RankingEngine(new PartySettings(array_merge(PartySettings::DEFAULTS, $overrides)));
    }

    public function test_more_hype_gives_higher_result(): void
    {
        $engine = $this->engine();

        $this->assertGreaterThan(
            $engine->score(hypeCount: 3, waitingMinutes: 0),
            $engine->score(hypeCount: 8, waitingMinutes: 0),
        );
    }

    /**
     * The key property of the whole product: an old track with few votes has to
     * overtake a fresh hit in the end. Otherwise the evening's first submission
     * would block the queue forever.
     */
    public function test_old_track_outranks_fresh_hit(): void
    {
        $engine = $this->engine();

        $stary = $engine->score(hypeCount: 2, waitingMinutes: 40);
        $swiezy = $engine->score(hypeCount: 9, waitingMinutes: 3);

        $this->assertGreaterThan($swiezy, $stary,
            'Kawalek z 2 hype czekajacy 40 min powinien wygrac z 9 hype czekajacym 3 min');
    }

    public function test_fresh_hit_wins_on_short_finish(): void
    {
        $engine = $this->engine();

        // Zaraz po wrzuceniu obu - decyduja same lapki.
        $this->assertGreaterThan(
            $engine->score(hypeCount: 2, waitingMinutes: 1),
            $engine->score(hypeCount: 9, waitingMinutes: 1),
        );
    }

    public function test_strength_ageing_changes_pace(): void
    {
        $slabe = $this->engine(['aging_strength' => 'weak'])->score(hypeCount: 0, waitingMinutes: 30);
        $srednie = $this->engine(['aging_strength' => 'medium'])->score(hypeCount: 0, waitingMinutes: 30);
        $mocne = $this->engine(['aging_strength' => 'strong'])->score(hypeCount: 0, waitingMinutes: 30);

        $this->assertLessThan($srednie, $slabe);
        $this->assertLessThan($mocne, $srednie);
    }

    public function test_penalty_for_artist_is_highest_right_after_playing(): void
    {
        $engine = $this->engine(['artist_cooldown' => 5]);

        $tuzPo = $engine->score(hypeCount: 10, waitingMinutes: 0, artistPlayedAgo: 0);
        $earlier = $engine->score(hypeCount: 10, waitingMinutes: 0, artistPlayedAgo: 3);
        $brak = $engine->score(hypeCount: 10, waitingMinutes: 0, artistPlayedAgo: null);

        $this->assertLessThan($earlier, $tuzPo);
        $this->assertLessThan($brak, $earlier);
    }

    public function test_outside_window_cooldown_penalty_has_no(): void
    {
        $engine = $this->engine(['artist_cooldown' => 5]);

        $this->assertSame(
            $engine->score(hypeCount: 4, waitingMinutes: 0, artistPlayedAgo: null),
            $engine->score(hypeCount: 4, waitingMinutes: 0, artistPlayedAgo: 5),
        );
    }

    public function test_penalty_for_guest_works_tak_itself(): void
    {
        $engine = $this->engine(['guest_cooldown' => 2]);

        $this->assertLessThan(
            $engine->score(hypeCount: 6, waitingMinutes: 0, guestPlayedAgo: null),
            $engine->score(hypeCount: 6, waitingMinutes: 0, guestPlayedAgo: 0),
        );
    }

    public function test_pinned_beats_everything(): void
    {
        $engine = $this->engine();

        $pinned = $engine->score(hypeCount: 0, waitingMinutes: 0, isPinned: true);
        $best = $engine->score(hypeCount: 999, waitingMinutes: 600);

        $this->assertGreaterThan($best, $pinned);
    }

    public function test_negative_time_waiting_not_breaks_result(): void
    {
        $engine = $this->engine();

        $this->assertSame(
            5.0,
            $engine->score(hypeCount: 5, waitingMinutes: -20),
        );
    }

    public function test_weight_hype_scales_votes(): void
    {
        $doubled = $this->engine(['hype_weight' => 2.0])->score(hypeCount: 5, waitingMinutes: 0);

        $this->assertSame(10.0, $doubled);
    }
}

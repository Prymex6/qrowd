<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\QueueItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PartyTestCase;

/**
 * The playing device. It is the only client allowed to change what is coming out
 * of the speakers - which is why it has a pairing token of its own rather than a
 * session.
 */
class PlayerTest extends PartyTestCase
{
    use RefreshDatabase;

    public function test_there_is_no_access_without_a_token(): void
    {
        $party = Party::factory()->create();

        $this->get("/player/{$party->code}")->assertForbidden();
        $this->getJson("/api/player/{$party->code}/state")->assertForbidden();
    }

    public function test_a_wrong_token_is_rejected(): void
    {
        $party = Party::factory()->create();

        $this->getJson("/api/player/{$party->code}/state?token=nieprawidlowy")->assertForbidden();
    }

    public function test_a_valid_token_returns_the_state(): void
    {
        $party = Party::factory()->create();

        $this->getJson("/api/player/{$party->code}/state?token={$party->player_token}")
            ->assertOk()
            ->assertJsonPath('status', 'live')
            ->assertJsonStructure(['status', 'nowPlaying', 'nextUp', 'settings']);
    }

    public function test_reading_the_state_records_the_players_presence(): void
    {
        $party = Party::factory()->create(['player_seen_at' => null]);

        $this->getJson("/api/player/{$party->code}/state?token={$party->player_token}");

        $this->assertNotNull($party->fresh()->player_seen_at,
            'Test przed impreza sprawdza wlasnie ten znacznik');
    }

    public function test_a_token_in_the_header_also_works(): void
    {
        $party = Party::factory()->create();

        $this->withHeader('X-Player-Token', $party->player_token)
            ->postJson("/api/player/{$party->code}/finished")
            ->assertOk();
    }

    public function test_ending_a_track_starts_the_next_one(): void
    {
        $party = Party::factory()->settings(['set_length' => 0])->create();

        $playing = QueueItem::factory()->playing()->create(['party_id' => $party->id]);
        $next = QueueItem::factory()->create(['party_id' => $party->id, 'hype_count' => 5]);

        $this->withHeader('X-Player-Token', $party->player_token)
            ->postJson("/api/player/{$party->code}/finished")
            ->assertOk()
            ->assertJsonPath('nowPlaying.id', $next->id);

        $this->assertSame('played', $playing->fresh()->status);
        $this->assertSame('playing', $next->fresh()->status);
    }

    public function test_skipping_marks_the_track_as_skipped(): void
    {
        $party = Party::factory()->settings(['set_length' => 0])->create();
        $playing = QueueItem::factory()->playing()->create(['party_id' => $party->id]);

        $this->withHeader('X-Player-Token', $party->player_token)
            ->postJson("/api/player/{$party->code}/skip")
            ->assertOk();

        $this->assertSame('skipped', $playing->fresh()->status);
    }

    /**
     * A regression: after a skip the next track started where the previous one
     * had ended. The player reports its position before it manages to switch the
     * video, so the server took the skipped song's "1:12" for the new one's
     * position and pushed its start marker back by 72 seconds.
     */
    public function test_a_position_report_never_lands_on_the_new_track(): void
    {
        $party = Party::factory()->settings(['set_length' => 0])->create();

        $playing = QueueItem::factory()->playing()->create(['party_id' => $party->id]);
        $next = QueueItem::factory()->create(['party_id' => $party->id, 'hype_count' => 5]);

        $this->withHeader('X-Player-Token', $party->player_token)
            ->postJson("/api/player/{$party->code}/skip")
            ->assertOk();

        // Spozniony meldunek o pomijanym utworze - dociera juz po podmianie.
        $this->getJson("/api/player/{$party->code}/state?token={$party->player_token}&position=72&track={$playing->id}")
            ->assertOk();

        $next->refresh();

        $this->assertLessThanOrEqual(
            2,
            abs($next->started_at->diffInSeconds(now())),
            'Nowy utwor musi zaczynac sie od poczatku, a nie od pozycji pominietego.'
        );
    }

    public function test_a_report_without_a_track_id_is_ignored(): void
    {
        $party = Party::factory()->create();
        $playing = QueueItem::factory()->playing()->create([
            'party_id' => $party->id, 'started_at' => now(),
        ]);

        $this->getJson("/api/player/{$party->code}/state?token={$party->player_token}&position=90")
            ->assertOk();

        $this->assertLessThanOrEqual(2, abs($playing->fresh()->started_at->diffInSeconds(now())));
    }

    /** Korekta wlasnego utworu ma dzialac - po to w ogole powstala. */
    public function test_a_report_about_its_own_track_corrects_the_marker(): void
    {
        $party = Party::factory()->create();
        $playing = QueueItem::factory()->playing()->create([
            'party_id' => $party->id, 'started_at' => now(),
        ]);

        // The player was buffering and is really only at the 40th second.
        $this->getJson("/api/player/{$party->code}/state?token={$party->player_token}&position=40&track={$playing->id}")
            ->assertOk();

        $this->assertEqualsWithDelta(40, $playing->fresh()->started_at->diffInSeconds(now()), 2);
    }

    public function test_the_state_honours_the_duration_trim(): void
    {
        $party = Party::factory()->settings(['max_track_seconds' => 180])->create();
        QueueItem::factory()->playing()->create(['party_id' => $party->id, 'duration_seconds' => 400]);

        $this->getJson("/api/player/{$party->code}/state?token={$party->player_token}")
            ->assertJsonPath('nowPlaying.playSeconds', 180);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\QueueItem;
use App\Services\PartyState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PartyTestCase;

/**
 * Pausing the party with the panic button.
 *
 * The host uses it in the moments when it MUST work at once: a speech, a toast,
 * somebody fainting on the dance floor. The music is to fall silent and the time
 * counter to stand still for everyone at the same moment.
 */
class PauseTest extends PartyTestCase
{
    use RefreshDatabase;

    public function test_pausing_records_the_moment_of_the_pause(): void
    {
        $party = Party::factory()->create(['status' => 'live']);

        $this->actingAs($party->user)
            ->postJson("/host/{$party->code}/api/status", ['status' => 'paused'])
            ->assertOk();

        $party->refresh();
        $this->assertSame('paused', $party->status);
        $this->assertNotNull($party->paused_at);
    }

    public function test_the_counter_stands_still_while_paused(): void
    {
        $party = Party::factory()->create(['status' => 'live']);
        QueueItem::factory()->playing()->create([
            'party_id' => $party->id, 'started_at' => now()->subSeconds(30), 'duration_seconds' => 200,
        ]);

        $this->actingAs($party->user)->postJson("/host/{$party->code}/api/status", ['status' => 'paused']);
        $party->refresh();

        $firstItem = PartyState::for($party)->forScreen()['nowPlaying']['elapsed'];

        // A minute of real time goes by - the counter must not move.
        $this->travel(60)->seconds();

        $drugi = PartyState::for($party)->forScreen()['nowPlaying']['elapsed'];

        $this->assertSame($firstItem, $drugi, 'Przy wstrzymanej imprezie czas nie moze plynac');
        $this->assertTrue(PartyState::for($party)->forScreen()['nowPlaying']['paused']);
    }

    public function test_the_player_sees_the_pause(): void
    {
        $party = Party::factory()->create(['status' => 'live']);
        QueueItem::factory()->playing()->create(['party_id' => $party->id]);

        $this->actingAs($party->user)->postJson("/host/{$party->code}/api/status", ['status' => 'paused']);
        $party->refresh();

        $this->getJson("/api/player/{$party->code}/state?token={$party->player_token}")
            ->assertJsonPath('status', 'paused')
            ->assertJsonPath('nowPlaying.paused', true);
    }

    /** Breaks are entries in the history, but they are not music. */
    public function test_the_played_counter_does_not_count_breaks(): void
    {
        $party = Party::factory()->create();

        QueueItem::factory()->count(3)->played()->create(['party_id' => $party->id]);
        QueueItem::factory()->played()->create(['party_id' => $party->id, 'title' => 'PRZERWA', 'source' => 'auto']);

        $this->assertSame(3, $party->playedItems()->count());
        $this->assertSame(3, PartyState::for($party)->forScreen()['stats']['playedCount']);
    }

    /**
     * By default a mute behaves as it would in any ordinary player: the track
     * returns to where it stopped. The host has a separate skip button when they
     * want to start something over - a mute need not do two things at once.
     */
    public function test_by_default_a_track_resumes_where_it_stopped(): void
    {
        $party = Party::factory()->create(['status' => 'live']);
        QueueItem::factory()->playing()->create([
            'party_id' => $party->id, 'started_at' => now()->subSeconds(90), 'duration_seconds' => 240,
        ]);

        $this->actingAs($party->user)->postJson("/host/{$party->code}/api/status", ['status' => 'paused']);
        $this->travel(180)->seconds();
        $this->actingAs($party->user)->postJson("/host/{$party->code}/api/status", ['status' => 'live']);

        $party->refresh();
        $mija = PartyState::for($party)->forScreen()['nowPlaying']['elapsed'];

        $this->assertEqualsWithDelta(90, $mija, 2,
            'Po wznowieniu utwor ma wrocic tam, gdzie stanal');
    }

    public function test_the_host_can_choose_to_restart_from_the_beginning(): void
    {
        $party = Party::factory()->settings(['resume_from_start' => true])->create(['status' => 'live']);
        QueueItem::factory()->playing()->create([
            'party_id' => $party->id, 'started_at' => now()->subSeconds(90), 'duration_seconds' => 240,
        ]);

        $this->actingAs($party->user)->postJson("/host/{$party->code}/api/status", ['status' => 'paused']);
        $this->travel(180)->seconds();
        $this->actingAs($party->user)->postJson("/host/{$party->code}/api/status", ['status' => 'live']);

        $party->refresh();
        $mija = PartyState::for($party)->forScreen()['nowPlaying']['elapsed'];

        $this->assertLessThanOrEqual(2, $mija,
            'Przy wlaczonym restarcie utwor ma leciec od poczatku');
    }
}

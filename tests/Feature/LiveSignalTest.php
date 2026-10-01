<?php

namespace Tests\Feature;

use App\Events\NowPlayingChanged;
use App\Events\QueueUpdated;
use App\Models\Party;
use App\Models\QueueItem;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Tests\PartyTestCase;

/**
 * Live signals (Reverb) are a convenience, not part of the transaction.
 *
 * The events broadcast synchronously, so a stopped WebSocket server used to throw
 * inside the request - and every submission and every hype answered with a 500.
 * The tests run on the null broadcaster, which is exactly why nobody saw it.
 */
class LiveSignalTest extends PartyTestCase
{
    use RefreshDatabase;

    private int $attempts = 0;

    /** A broadcaster behaving like Reverb that is not running. */
    private function reverbIsDown(): void
    {
        $test = $this;

        Broadcast::extend('down', fn () => new class($test) extends Broadcaster
        {
            public function __construct(private $test) {}

            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->test->countAttempt();

                throw new BroadcastException('cURL error 7: Failed to connect to 127.0.0.1 port 8080');
            }
        });

        config(['broadcasting.connections.down' => ['driver' => 'down'], 'broadcasting.default' => 'down']);
        Cache::flush();
    }

    public function countAttempt(): void
    {
        $this->attempts++;
    }

    public function test_a_submission_still_succeeds_when_reverb_is_down(): void
    {
        $this->reverbIsDown();
        $party = Party::factory()->create();
        [, $deviceId] = $this->guestFor($party);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/queue", $this->trackPayload(['title' => 'Ona Tanczy']))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('queue_items', ['party_id' => $party->id, 'title' => 'Ona Tanczy']);
    }

    public function test_a_hype_still_succeeds_when_reverb_is_down(): void
    {
        $this->reverbIsDown();
        $party = Party::factory()->create();
        [, $deviceId] = $this->guestFor($party);
        $item = QueueItem::factory()->create(['party_id' => $party->id]);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/queue/{$item->id}/hype")
            ->assertOk();

        $this->assertSame(1, $item->fresh()->hype_count);
    }

    /**
     * After one failure the following requests skip broadcasting for a while -
     * otherwise each of them would wait out the two-second connection timeout.
     */
    public function test_after_a_failure_requests_stop_waiting_on_reverb(): void
    {
        $this->reverbIsDown();
        $party = Party::factory()->create();
        [, $deviceId] = $this->guestFor($party);

        foreach (['Pierwszy', 'Drugi', 'Trzeci'] as $title) {
            $this->asGuest($deviceId)
                ->postJson("/api/p/{$party->code}/queue", $this->trackPayload(['title' => $title]))
                ->assertOk();
        }

        $this->assertSame(1, $this->attempts, 'Reverb was asked again despite having just failed.');
    }

    /** The client listens under exactly these names - a mismatch loses the signal silently. */
    public function test_event_names_match_what_the_browser_listens_for(): void
    {
        $party = Party::factory()->create();
        $client = file_get_contents(resource_path('js/live.js'));

        foreach ([new QueueUpdated($party), new NowPlayingChanged($party, null)] as $event) {
            $this->assertStringContainsString("'.".$event->broadcastAs()."'", $client);
        }
    }
}

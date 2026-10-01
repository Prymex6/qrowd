<?php

namespace Tests\Feature;

use App\Models\ApiUsage;
use App\Models\CatalogTrack;
use App\Models\Party;
use App\Services\MusicSearch;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\PartyTestCase;

/**
 * The search - the catalogue layer.
 *
 * MIND how the database is cleaned here: we use DatabaseTruncation, not
 * RefreshDatabase. InnoDB updates a FULLTEXT index only when a transaction
 * commits, and RefreshDatabase keeps a whole test inside one uncommitted
 * transaction - the search would then not see the inserted rows and the tests
 * would pass against nothing.
 */
class SearchTest extends PartyTestCase
{
    use DatabaseTruncation;

    private function directory(array $tracks): void
    {
        foreach ($tracks as [$title, $artistName]) {
            CatalogTrack::factory()->create(['title' => $title, 'artist' => $artistName]);
        }
    }

    public function test_it_finds_a_track_by_title(): void
    {
        $this->directory([
            ['Ona Tanczy Dla Mnie', 'Weekend'],
            ['Zycie Jest Nowela', 'Bayer Full'],
        ]);

        $result = MusicSearch::make()->suggest('tanczy');

        $this->assertSame('catalog', $result['layer']);
        $this->assertCount(1, $result['tracks']);
        $this->assertSame('Ona Tanczy Dla Mnie', $result['tracks'][0]['title']);
    }

    public function test_it_finds_a_track_by_artist(): void
    {
        $this->directory([['Przez Twe Oczy Zielone', 'Zenek Martyniuk']]);

        $result = MusicSearch::make()->suggest('martyniuk');

        $this->assertCount(1, $result['tracks']);
    }

    /**
     * FULLTEXT in natural mode matched anything, so the query "sto lat" returned
     * tracks containing the word "lat" alone. At a wedding that meant a guest
     * looking for "Sto lat" got random disco polo.
     */
    public function test_it_requires_every_word_of_the_query(): void
    {
        $this->directory([
            ['Piosenka z tamtych lat', 'Sekret'],
            ['Sto lat dla jubilata', 'Biesiada'],
        ]);

        $result = MusicSearch::make()->suggest('sto lat');

        $tytuly = array_column($result['tracks'], 'title');

        $this->assertContains('Sto lat dla jubilata', $tytuly);
        $this->assertNotContains('Piosenka z tamtych lat', $tytuly,
            'Zapytanie "sto lat" nie moze lapac utworu z samym slowem "lat"');
    }

    public function test_an_empty_or_too_short_query_returns_nothing(): void
    {
        $this->directory([['Cokolwiek', 'Ktos']]);

        $this->assertEmpty(MusicSearch::make()->suggest('')['tracks']);
        $this->assertEmpty(MusicSearch::make()->suggest('a')['tracks']);
    }

    public function test_it_hides_tracks_that_cannot_be_embedded(): void
    {
        CatalogTrack::factory()->notEmbeddable()->create(['title' => 'Zablokowany Utwor', 'artist' => 'X']);

        $this->assertEmpty(MusicSearch::make()->suggest('banned')['tracks']);
    }

    public function test_the_profanity_filter_removes_explicit_results(): void
    {
        CatalogTrack::factory()->explicit()->create(['title' => 'Ostry Kawalek', 'artist' => 'Raper']);

        $wesele = Party::factory()->settings(['filter_explicit' => true])->create();
        $houseParty = Party::factory()->settings(['filter_explicit' => false])->create();

        $this->assertEmpty(MusicSearch::make()->suggest('ostry', $wesele)['tracks']);
        $this->assertCount(1, MusicSearch::make()->suggest('ostry', $houseParty)['tracks']);
    }

    public function test_it_drops_tracks_outside_the_duration_limits(): void
    {
        CatalogTrack::factory()->create([
            'title' => 'Skladanka Wieczorna', 'artist' => 'DJ', 'duration_seconds' => 3600,
        ]);

        $party = Party::factory()->settings(['max_video_seconds' => 480])->create();

        $this->assertEmpty(MusicSearch::make()->suggest('skladanka', $party)['tracks']);
    }

    public function test_the_artist_blocklist_applies_to_search(): void
    {
        CatalogTrack::factory()->create(['title' => 'Jakis Kawalek', 'artist' => 'Zenek Martyniuk']);

        $party = Party::factory()->create();
        $party->blocks()->create(['type' => 'artist', 'value' => 'Zenek Martyniuk']);
        $party->load('blocks');

        $this->assertEmpty(MusicSearch::make()->suggest('kawalek', $party)['tracks']);
    }

    /** Suggestions must NEVER reach for YouTube - that would be 100 units per keystroke. */
    public function test_suggestions_spend_no_quota(): void
    {
        $this->directory([['Cokolwiek Innego', 'Ktos']]);

        MusicSearch::make()->suggest('zupelnie nieistniejacy utwor xyz');

        // The third argument of assertDatabaseCount is a connection name, not a
        // message, so we give our own message through assertSame.
        $this->assertSame(0, ApiUsage::count(),
            'Podpowiedzi w trakcie pisania nie moga wolac YouTube');
    }

    public function test_it_reports_whether_youtube_is_available(): void
    {
        $otwarta = Party::factory()->settings(['catalog_only' => false])->create();
        $zamknieta = Party::factory()->settings(['catalog_only' => true])->create();

        $this->assertTrue(MusicSearch::make()->suggest('cos', $otwarta)['youtube_available']);
        $this->assertFalse(MusicSearch::make()->suggest('cos', $zamknieta)['youtube_available']);
    }

    public function test_catalogue_only_mode_never_reaches_youtube(): void
    {
        $party = Party::factory()->settings(['catalog_only' => true])->create();

        $result = MusicSearch::make()->searchYouTube('anything', $party);

        $this->assertSame('catalog_only', $result['layer']);
        $this->assertDatabaseCount('api_usage', 0);
    }

    public function test_the_suggestions_endpoint_returns_json(): void
    {
        $this->directory([['Ona Tanczy Dla Mnie', 'Weekend']]);
        $party = Party::factory()->create();
        [, $deviceId] = $this->guestFor($party);

        $this->asGuest($deviceId)
            ->getJson("/api/p/{$party->code}/search?q=tanczy")
            ->assertOk()
            ->assertJsonPath('layer', 'catalog')
            ->assertJsonPath('tracks.0.title', 'Ona Tanczy Dla Mnie');
    }

    public function test_searching_youtube_requires_a_guest(): void
    {
        $party = Party::factory()->create();

        $this->getJson("/api/p/{$party->code}/search-youtube?q=cos")->assertStatus(403);
    }
}

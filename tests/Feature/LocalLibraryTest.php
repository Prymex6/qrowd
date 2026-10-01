<?php

namespace Tests\Feature;

use App\Models\ApiUsage;
use App\Models\CatalogTrack;
use App\Models\Party;
use App\Models\QueueItem;
use App\Models\User;
use App\Services\LocalLibrary;
use App\Services\MusicSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\PartyTestCase;

/**
 * Playing from the host's own library.
 *
 * A wedding venue can turn out to have no internet, and then the whole
 * application is useless despite a full queue.
 *
 * THE ARCHITECTURE these tests guard: the server NEVER sees or reads the files.
 * The folder is chosen by the browser on the laptop wired to the speakers; that
 * browser reads the names and durations and sends THE LISTING ALONE. The sound
 * never leaves that computer - which is what lets QRowd sit on any hosting.
 */
class LocalLibraryTest extends PartyTestCase
{
    use RefreshDatabase;

    /** One entry of the listing, shaped the way the browser sends it. */
    private function pozycja(array $n = []): array
    {
        return array_merge([
            'path' => 'Sanah/Sanah - Chwile ulotne.mp3',
            'title' => 'Chwile ulotne',
            'artist' => 'Sanah',
            'duration' => 210,
        ], $n);
    }

    /** Sends the listing the way the player does. */
    private function wyslijSpis(User $host, array $tracks, bool $first = true)
    {
        return $this->actingAs($host)->postJson('/host/muzyka/indeks', [
            'folder' => 'Muzyka',
            'first' => $first,
            'tracks' => $tracks,
        ]);
    }

    private function utworZDysku(User $host, array $n = []): CatalogTrack
    {
        $p = $this->pozycja($n);

        return CatalogTrack::factory()->create([
            'youtube_id' => LocalLibrary::idFor($p['path']),
            'local_path' => $p['path'],
            'user_id' => $host->id,
            'title' => $p['title'],
            'artist' => $p['artist'],
            'duration_seconds' => $p['duration'],
            'is_embeddable' => true,
        ]);
    }

    // ==================================================== identyfikacja

    /**
     * The youtube_id column is required and unique, and all the existing code
     * (the repeat block, merging duplicates) rests on it. So a track from disk
     * gets an identifier of its own, stable, rather than nothing.
     *
     * The browser computes the same value - were the two to diverge, the player
     * would not find the file behind a queue entry.
     */
    public function test_the_identifier_is_stable_and_fits_the_column(): void
    {
        $id = LocalLibrary::idFor('Sanah/Chwile ulotne.mp3');

        $this->assertSame($id, LocalLibrary::idFor('Sanah/Chwile ulotne.mp3'));
        $this->assertSame($id, LocalLibrary::idFor('Sanah\\Chwile ulotne.mp3'),
            'Ukosnik w dwie strony to ta sama sciezka.');
        $this->assertLessThanOrEqual(20, strlen($id));
        $this->assertNotSame($id, LocalLibrary::idFor('Sanah/Inny.mp3'));
    }

    // ==================================================== przyjmowanie spisu

    public function test_the_server_accepts_a_listing_from_the_browser(): void
    {
        $host = User::factory()->create();

        $this->wyslijSpis($host, [$this->pozycja()])
            ->assertOk()
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('inLibrary', 1);

        $track = CatalogTrack::whereNotNull('local_path')->first();

        $this->assertSame('Chwile ulotne', $track->title);
        $this->assertSame($host->id, $track->user_id);
        $this->assertSame(LocalLibrary::idFor($this->pozycja()['path']), $track->youtube_id);
    }

    /**
     * Walking the folder is exhaustive, so the first batch starts the library
     * afresh - otherwise tracks would be left behind for files the host has since
     * deleted from the disk.
     */
    public function test_the_first_batch_starts_the_library_over(): void
    {
        $host = User::factory()->create();
        $this->utworZDysku($host, ['path' => 'Stare/Usuniety.mp3']);

        $this->wyslijSpis($host, [$this->pozycja()], first: true)->assertOk();

        $this->assertSame(1, CatalogTrack::whereNotNull('local_path')->count());
        $this->assertNull(CatalogTrack::where('local_path', 'Stare/Usuniety.mp3')->first());
    }

    public function test_later_batches_append_to_the_library(): void
    {
        $host = User::factory()->create();

        $this->wyslijSpis($host, [$this->pozycja()], first: true)->assertOk();
        $this->wyslijSpis($host, [
            $this->pozycja(['path' => 'Sanah/Sanah - Szampan.mp3', 'title' => 'Szampan']),
        ], first: false)->assertJsonPath('inLibrary', 2);
    }

    public function test_the_listing_remembers_the_folder_name(): void
    {
        $host = User::factory()->create();

        $this->wyslijSpis($host, [$this->pozycja()])->assertOk();

        $this->assertSame('Muzyka', $host->fresh()->music_folder);
    }

    public function test_an_explicit_title_is_flagged_on_intake(): void
    {
        $host = User::factory()->create();

        $this->wyslijSpis($host, [$this->pozycja(['title' => 'Ostry Kawalek (Explicit)'])])->assertOk();

        $track = CatalogTrack::whereNotNull('local_path')->first();

        $this->assertTrue((bool) $track->is_explicit);
        $this->assertFalse((bool) $track->is_wedding_safe);
    }

    public function test_uploading_a_listing_requires_signing_in(): void
    {
        $this->postJson('/host/muzyka/indeks', [
            'folder' => 'Muzyka', 'tracks' => [$this->pozycja()],
        ])->assertUnauthorized();
    }

    // ==================================================== prywatnosc bibliotek

    /**
     * The library is PRIVATE - these are files sitting on one particular host's
     * laptop. We do not show anyone else's, because the player would have nothing
     * to open, and one host scanning a folder must not wipe another's library.
     */
    public function test_a_host_never_sees_another_hosts_library(): void
    {
        $mine = User::factory()->create();
        $stranger = User::factory()->create();

        $this->utworZDysku($stranger, ['path' => 'Obcy/Cudzy utwor.mp3', 'title' => 'Chwile obce']);

        $party = Party::factory()->settings(['music_source' => 'disk'])
            ->create(['user_id' => $mine->id]);

        $this->assertEmpty(MusicSearch::make()->suggest('chwile', $party)['tracks']);
    }

    public function test_loading_a_folder_leaves_other_libraries_alone(): void
    {
        $mine = User::factory()->create();
        $stranger = User::factory()->create();

        $this->utworZDysku($stranger, ['path' => 'Obcy/Cudzy.mp3']);

        $this->wyslijSpis($mine, [$this->pozycja()], first: true)->assertOk();

        $this->assertSame(1, CatalogTrack::where('user_id', $stranger->id)->count(),
            'Biblioteka innego hosta zostala skasowana.');
    }

    public function test_a_host_can_forget_their_own_library(): void
    {
        $host = User::factory()->create();
        $this->utworZDysku($host);
        $host->forceFill(['music_folder' => 'Muzyka'])->save();

        $this->actingAs($host)->deleteJson('/host/muzyka')->assertOk();

        $this->assertNull($host->fresh()->music_folder);
        $this->assertSame(0, CatalogTrack::whereNotNull('local_path')->count());
    }

    // ==================================================== wyszukiwarka

    public function test_disk_mode_shows_only_the_hosts_library(): void
    {
        $host = User::factory()->create();
        $this->utworZDysku($host);
        CatalogTrack::factory()->create(['title' => 'Chwile z YouTube', 'artist' => 'Ktos']);

        $party = Party::factory()->settings(['music_source' => 'disk'])
            ->create(['user_id' => $host->id]);

        $result = MusicSearch::make()->suggest('chwile', $party)['tracks'];

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['fromDisk']);
    }

    public function test_youtube_mode_lets_in_no_files_from_disk(): void
    {
        $host = User::factory()->create();
        $this->utworZDysku($host);
        CatalogTrack::factory()->create(['title' => 'Chwile z YouTube', 'artist' => 'Ktos']);

        $party = Party::factory()->settings(['music_source' => 'youtube'])
            ->create(['user_id' => $host->id]);

        $result = MusicSearch::make()->suggest('chwile', $party)['tracks'];

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['fromDisk']);
    }

    /** With no internet, reaching for YouTube makes no sense and costs nothing. */
    public function test_disk_mode_never_reaches_out_to_youtube(): void
    {
        $host = User::factory()->create();
        $this->utworZDysku($host);

        $party = Party::factory()->settings(['music_source' => 'disk'])
            ->create(['user_id' => $host->id]);

        $result = MusicSearch::make()->searchYouTube('chwile', $party);

        $this->assertSame('disk', $result['layer']);
        $this->assertSame(0, ApiUsage::count());
    }

    // ==================================================== kolejka i odtwarzacz

    public function test_a_disk_submission_carries_the_file_path(): void
    {
        $host = User::factory()->create();
        $track = $this->utworZDysku($host);

        $party = Party::factory()->settings(['music_source' => 'disk'])
            ->create(['user_id' => $host->id, 'status' => 'live']);
        [$guest, $key] = $this->guestFor($party);

        $this->asGuest($key)->postJson("/api/p/{$party->code}/queue", [
            'youtube_id' => $track->youtube_id,
            'title' => $track->title,
            'artist' => $track->artist,
            'duration_seconds' => $track->duration_seconds,
            'catalog_track_id' => $track->id,
        ])->assertOk();

        $this->assertSame($track->local_path, $party->queueItems()->first()->local_path);
    }

    /**
     * The path must NOT be taken from the request. If a guest's browser could
     * supply it, they would point at any file on the host's laptop.
     */
    public function test_a_guest_cannot_supply_their_own_path(): void
    {
        $party = Party::factory()->create(['status' => 'live']);
        [$guest, $key] = $this->guestFor($party);

        $this->asGuest($key)->postJson("/api/p/{$party->code}/queue",
            $this->trackPayload(['local_path' => '../../../../.env']))->assertOk();

        $this->assertNull($party->queueItems()->first()->local_path);
    }

    /** Odtwarzacz dostaje sciezke wzgledna - otworzy plik sam, przez uchwyt. */
    public function test_the_player_receives_a_path_not_a_server_url(): void
    {
        $host = User::factory()->create();
        $track = $this->utworZDysku($host);

        $party = Party::factory()->settings(['music_source' => 'disk'])
            ->create(['user_id' => $host->id, 'status' => 'live']);

        QueueItem::factory()->playing()->create([
            'party_id' => $party->id,
            'youtube_id' => $track->youtube_id,
            'local_path' => $track->local_path,
        ]);

        $this->getJson("/api/player/{$party->code}/state?token={$party->player_token}")
            ->assertJsonPath('nowPlaying.fromDisk', true)
            ->assertJsonPath('nowPlaying.path', $track->local_path)
            ->assertJsonPath('source', 'disk');
    }

    public function test_a_youtube_track_never_poses_as_a_file(): void
    {
        $party = Party::factory()->create(['status' => 'live']);
        QueueItem::factory()->playing()->create(['party_id' => $party->id]);

        $this->getJson("/api/player/{$party->code}/state?token={$party->player_token}")
            ->assertJsonPath('nowPlaying.fromDisk', false)
            ->assertJsonPath('nowPlaying.path', null);
    }

    /**
     * The server must have NO route at all that hands over a file from the disk.
     * If one existed, the whole idea - the music stays on the host's laptop, the
     * application sits on hosting - would stop holding.
     */
    public function test_the_server_exposes_no_route_serving_files(): void
    {
        $trasy = collect(Route::getRoutes())
            ->map(fn ($t) => $t->uri())
            ->filter(fn ($u) => str_contains($u, 'file') && str_contains($u, 'player'));

        $this->assertCount(0, $trasy,
            'Serwer nie moze strumieniowac muzyki z dysku: '.$trasy->implode(', '));
    }
}

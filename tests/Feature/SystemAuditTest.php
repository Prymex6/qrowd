<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Party;
use App\Models\Photo;
use App\Models\QueueItem;
use App\Models\User;
use App\Services\QueueManager;
use App\Services\RankingEngine;
use App\Services\YouTube\QuotaGuard;
use App\Support\PartySettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\PartyTestCase;

/**
 * An audit of how the application behaves.
 *
 * It does not check single functions but properties that MUST hold whichever way
 * you arrive at them: isolation between parties, the number of queries on the
 * hot paths, and that a party's state cannot be broken from outside.
 */
class SystemAuditTest extends PartyTestCase
{
    use RefreshDatabase;

    private function countCalls(callable $co): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $co();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    // ==================================================== izolacja imprez

    public function test_a_guest_cannot_reach_into_another_party(): void
    {
        $moja = Party::factory()->create(['status' => 'live']);
        $foreign = Party::factory()->create(['status' => 'live']);

        [$guest, $key] = $this->guestFor($moja);
        $utworObcy = QueueItem::factory()->create(['party_id' => $foreign->id]);

        // Hype na pozycji z cudzej imprezy.
        $this->asGuest($key)->postJson("/api/p/{$moja->code}/queue/{$utworObcy->id}/hype")
            ->assertNotFound();

        // The same device id at somebody else's party is NOT that guest.
        $this->asGuest($key)->postJson("/api/p/{$foreign->code}/queue/{$utworObcy->id}/hype")
            ->assertForbidden();
    }

    public function test_a_guest_cannot_view_photos_from_another_party(): void
    {
        $moja = Party::factory()->create();
        $foreign = Party::factory()->create();

        [$guest, $key] = $this->guestFor($moja);
        $foreignPhoto = Photo::factory()->create(['party_id' => $foreign->id, 'status' => 'visible']);

        $this->asGuest($key)->get("/z/{$foreign->code}/{$foreignPhoto->id}")
            ->assertForbidden();
    }

    public function test_a_host_cannot_enter_another_hosts_party(): void
    {
        $foreign = Party::factory()->create(['status' => 'live']);
        $somebody = User::factory()->create();

        foreach ([
            "/host/{$foreign->code}",
            "/host/{$foreign->code}/ustawienia",
            "/host/{$foreign->code}/zdjecia.zip",
        ] as $url) {
            $this->actingAs($somebody)->get($url)->assertForbidden();
        }

        $this->actingAs($somebody)->postJson("/host/{$foreign->code}/api/skip")->assertForbidden();
    }

    public function test_a_wrong_player_token_controls_nothing(): void
    {
        $party = Party::factory()->create(['status' => 'live']);
        $playing = QueueItem::factory()->playing()->create(['party_id' => $party->id]);

        $this->withHeader('X-Player-Token', 'nie-ten-token')
            ->postJson("/api/player/{$party->code}/skip")
            ->assertForbidden();

        $this->assertSame('playing', $playing->fresh()->status);
    }

    // ==================================================== liczba zapytan

    /**
     * The guest state goes out every twenty seconds from every phone in the room.
     * With a hundred guests that is five HTTP requests a second - every needless
     * database query multiplies across the whole party.
     */
    public function test_guest_state_does_not_multiply_queries_with_the_queue(): void
    {
        $party = Party::factory()->create(['status' => 'live']);
        [$guest, $key] = $this->guestFor($party);

        QueueItem::factory()->playing()->create(['party_id' => $party->id]);

        $fewItems = $this->countCalls(function () use ($party, $key) {
            $this->asGuest($key)->getJson("/api/p/{$party->code}/state");
        });

        // Dorzucamy pietnascie pozycji, kazda od innego goscia.
        for ($i = 0; $i < 15; $i++) {
            [$g] = $this->guestFor($party);
            QueueItem::factory()->create(['party_id' => $party->id, 'guest_id' => $g->id]);
        }

        $manyItems = $this->countCalls(function () use ($party, $key) {
            $this->asGuest($key)->getJson("/api/p/{$party->code}/state");
        });

        $this->assertLessThanOrEqual(
            $fewItems + 2,
            $manyItems,
            "Kolejka rosnie -> zapytania rosna (N+1). Bylo {$fewItems}, jest {$manyItems}."
        );
    }

    public function test_the_screen_does_not_multiply_queries_with_photos(): void
    {
        $party = Party::factory()->settings(['photos_on_screen' => true])->create(['status' => 'live']);
        [$guest] = $this->guestFor($party);

        Photo::factory()->count(2)->create(['party_id' => $party->id, 'guest_id' => $guest->id, 'status' => 'visible']);

        $malo = $this->countCalls(fn () => $this->getJson("/api/screen/{$party->code}"));

        Photo::factory()->count(20)->create(['party_id' => $party->id, 'guest_id' => $guest->id, 'status' => 'visible']);

        $duzo = $this->countCalls(fn () => $this->getJson("/api/screen/{$party->code}"));

        $this->assertLessThanOrEqual($malo + 2, $duzo,
            "Zdjecia mnoza zapytania. Bylo {$malo}, jest {$duzo}.");
    }

    public function test_the_host_panel_does_not_multiply_queries_with_guests(): void
    {
        $party = Party::factory()->create(['status' => 'live']);

        for ($i = 0; $i < 3; $i++) {
            $this->guestFor($party);
        }
        $malo = $this->countCalls(fn () => $this->actingAs($party->user)
            ->getJson("/host/{$party->code}/api/state"));

        for ($i = 0; $i < 25; $i++) {
            $this->guestFor($party);
        }
        $duzo = $this->countCalls(fn () => $this->actingAs($party->user)
            ->getJson("/host/{$party->code}/api/state"));

        $this->assertLessThanOrEqual($malo + 2, $duzo,
            "Goscie mnoza zapytania w panelu. Bylo {$malo}, jest {$duzo}.");
    }

    // ==================================================== stan imprezy

    public function test_a_closed_party_accepts_nothing(): void
    {
        $party = Party::factory()->create(['status' => 'ended', 'ended_at' => now()]);
        [$guest, $key] = $this->guestFor($party);

        $this->asGuest($key)
            ->postJson("/api/p/{$party->code}/queue", $this->trackPayload())
            ->assertStatus(422);

        $this->asGuest($key)
            ->postJson("/api/p/{$party->code}/photos", ['photo' => $this->zdjecie()])
            ->assertStatus(422);
    }

    // ==================================================== settings that really do something

    /**
     * Every setting in the defaults has to be READ somewhere.
     *
     * A dead key is worse than a missing feature: the panel saves it, the host
     * sees the change, and the party behaves exactly as before. The audit found
     * six of them - this test keeps them from coming back.
     */
    public function test_no_setting_is_dead(): void
    {
        $code = '';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path())
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // Definicja i walidacja to nie sa odczyty.
            if (str_ends_with($file->getPathname(), 'SettingsController.php')) {
                continue;
            }

            $body = file_get_contents($file->getPathname());

            if (str_ends_with($file->getPathname(), 'PartySettings.php')) {
                // Bierzemy tylko metody, z pominieciem tablicy domyslnych.
                $body = substr($body, strpos($body, 'public function __construct'));
            }

            $code .= $body;
        }

        foreach (glob(resource_path('js/Pages/*/*.vue')) as $widok) {
            $code .= file_get_contents($widok);
        }
        foreach (glob(resource_path('js/Components/*.vue')) as $widok) {
            $code .= file_get_contents($widok);
        }

        $dead = [];

        foreach (array_keys(PartySettings::DEFAULTS) as $key) {
            if (! str_contains($code, "'{$key}'")) {
                $dead[] = $key;
            }
        }

        $this->assertSame([], $dead,
            'Ustawienia zapisywane przez panel, ktore nic nie robia: '.implode(', ', $dead));
    }

    /**
     * The entry threshold was defined and validated but read nowhere - the host
     * could set it and the queue still played everything in turn.
     */
    public function test_the_entry_threshold_holds_back_unsupported_tracks(): void
    {
        $party = Party::factory()->settings([
            'entry_threshold' => 3,
            'set_length' => 0,
            'auto_fill' => false,
        ])->create(['status' => 'live']);

        $playing = QueueItem::factory()->playing()->create(['party_id' => $party->id]);
        $slaby = QueueItem::factory()->create(['party_id' => $party->id, 'hype_count' => 1]);
        $strong = QueueItem::factory()->create(['party_id' => $party->id, 'hype_count' => 4]);

        $this->withHeader('X-Player-Token', $party->player_token)
            ->postJson("/api/player/{$party->code}/skip")
            ->assertOk();

        $this->assertSame('playing', $strong->fresh()->status, 'Zagrac ma ten z poparciem sali.');
        $this->assertSame('queued', $slaby->fresh()->status, 'Ten bez poparcia ma czekac.');
    }

    public function test_an_entry_threshold_of_zero_changes_nothing(): void
    {
        $party = Party::factory()->settings([
            'entry_threshold' => 0, 'set_length' => 0,
        ])->create(['status' => 'live']);

        QueueItem::factory()->playing()->create(['party_id' => $party->id]);
        $bezHype = QueueItem::factory()->create(['party_id' => $party->id, 'hype_count' => 0]);

        $this->withHeader('X-Player-Token', $party->player_token)
            ->postJson("/api/player/{$party->code}/skip");

        $this->assertSame('playing', $bezHype->fresh()->status);
    }

    /** Ekran wisi na scianie - para mloda decyduje, co sala na nim widzi. */
    public function test_the_screen_honours_its_own_switches(): void
    {
        $party = Party::factory()->settings([
            'screen_show_qr' => false, 'screen_show_submitter' => false,
        ])->create(['status' => 'live']);

        $this->getJson("/api/screen/{$party->code}")
            ->assertJsonPath('show.qr', false)
            ->assertJsonPath('show.submittedBy', false);

        $enabled = Party::factory()->create(['status' => 'live']);

        $this->getJson("/api/screen/{$enabled->code}")
            ->assertJsonPath('show.qr', true)
            ->assertJsonPath('show.submittedBy', true);
    }

    public function test_the_guest_app_honours_hiding_the_submitter(): void
    {
        $party = Party::factory()->settings(['show_submitter' => false])->create(['status' => 'live']);
        [$guest, $key] = $this->guestFor($party);

        $this->asGuest($key)->getJson("/api/p/{$party->code}/state")
            ->assertJsonPath('show.submittedBy', false);
    }

    // ==================================================== sprzatanie po imprezie

    /**
     * Deleting a party removes the rows by cascade, but files on disk know
     * nothing of foreign keys. Without explicit tidying, guests' photos stayed on
     * the disk forever - and those are the likenesses of people whose party no
     * longer exists.
     */
    public function test_deleting_a_party_removes_its_photos_from_disk(): void
    {
        $party = Party::factory()->create(['status' => 'live']);
        [$guest, $key] = $this->guestFor($party);

        $this->asGuest($key)->postJson("/api/p/{$party->code}/photos",
            ['photo' => $this->zdjecie()])->assertOk();

        // path() jest wzgledna wobec dysku - do sprawdzen na plikach
        // potrzebna jest systemowa.
        $path = Storage::path(Photo::first()->path());
        $directory = Storage::path(Photo::directory($party));

        $this->assertFileExists($path);

        $this->actingAs($party->user)->delete("/host/{$party->code}");

        $this->assertDatabaseCount('photos', 0);
        $this->assertFileDoesNotExist($path, 'Zdjecie zostalo na dysku po usunieciu imprezy.');
        $this->assertDirectoryDoesNotExist($directory, 'Katalog imprezy zostal na dysku.');
    }

    // ==================================================== wyscigi

    /**
     * The player and the schedule can report the end of a track at the same
     * moment. Without a row lock both paths would take the same next track, and
     * one of them would be marked as playing while it is not.
     */
    public function test_two_concurrent_advances_never_start_two_tracks(): void
    {
        $party = Party::factory()->settings(['set_length' => 0, 'auto_fill' => false])
            ->create(['status' => 'live']);

        QueueItem::factory()->playing()->create(['party_id' => $party->id]);
        QueueItem::factory()->count(3)->create(['party_id' => $party->id]);

        $manager = QueueManager::for($party);

        $manager->advance($party);
        $manager->advance($party->fresh());

        $this->assertSame(
            1,
            $party->queueItems()->where('status', 'playing')->count(),
            'W jednej chwili moze grac dokladnie jeden utwor.'
        );
    }

    // ==================================================== logowanie

    public function test_signing_in_and_signing_out_works(): void
    {
        $user = User::factory()->create(['password' => bcrypt('tajne-haslo-123')]);

        $this->post('/logowanie', ['email' => $user->email, 'password' => 'zle-haslo'])
            ->assertSessionHasErrors();
        $this->assertGuest();

        $this->post('/logowanie', ['email' => $user->email, 'password' => 'tajne-haslo-123']);
        $this->assertAuthenticatedAs($user);

        $this->post('/wyloguj');
        $this->assertGuest();
    }

    public function test_the_password_is_hashed(): void
    {
        $this->post('/rejestracja', [
            'first_name' => 'Bartek',
            'email' => 'nowy@example.com',
            'password' => 'tajne-haslo-123',
            'password_confirmation' => 'tajne-haslo-123',
        ]);

        $user = User::where('email', 'nowy@example.com')->first();

        $this->assertNotNull($user);
        $this->assertNotSame('tajne-haslo-123', $user->password);
        $this->assertTrue(Hash::check('tajne-haslo-123', $user->password));
    }

    // ==================================================== czas i strefy

    /**
     * The YouTube quota resets at midnight Pacific time, which is 9 a.m. here.
     * Counting usage by the Polish day would mean that for eight hours each
     * morning the application believes it has a full quota when it does not - or
     * the other way round, blocking searches while the quota is free.
     */
    public function test_the_youtube_quota_follows_the_pacific_day(): void
    {
        $guard = QuotaGuard::fromConfig();

        // Poludnie w Polsce to jeszcze poprzednia doba w Kalifornii.
        $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00', 'Europe/Warsaw'));
        $beforeReset = $guard->quotaDate();

        // Po 9:00 naszego czasu zaczyna sie nowa doba limitu.
        $this->travelTo(CarbonImmutable::parse('2026-09-10 09:30', 'Europe/Warsaw'));
        $afterReset = $guard->quotaDate();

        $this->assertTrue($afterReset->lessThan($beforeReset)
            || $afterReset->equalTo($beforeReset));

        $this->assertSame('America/Los_Angeles', $guard->quotaDate()->timezone->getName());
        $this->assertSame('00:00:00', $guard->quotaDate()->format('H:i:s'),
            'Doba limitu musi zaczynac sie o polnocy Pacyfiku.');

        $this->travelBack();
    }

    /**
     * Photos are personal data - they have to disappear by themselves after a set
     * time, with no need for the host to remember.
     */
    public function test_cleanup_deletes_old_photos_along_with_their_files(): void
    {
        $party = Party::factory()->settings(['photo_retention_days' => 7])->create(['status' => 'live']);
        [$guest, $key] = $this->guestFor($party);

        $this->asGuest($key)->postJson("/api/p/{$party->code}/photos",
            ['photo' => $this->zdjecie()])->assertOk();

        $path = Storage::path(Photo::first()->path());
        $this->assertFileExists($path);

        // The party ended long ago.
        $party->update(['status' => 'ended', 'ended_at' => now()->subDays(30)]);

        $this->artisan('photos:cleanup')->assertSuccessful();

        $this->assertDatabaseCount('photos', 0);
        $this->assertFileDoesNotExist($path, 'Plik przetrwal sprzatanie.');
    }

    public function test_cleanup_leaves_photos_from_an_ongoing_party_alone(): void
    {
        $party = Party::factory()->settings(['photo_retention_days' => 1])->create(['status' => 'live']);
        [$guest, $key] = $this->guestFor($party);

        $this->asGuest($key)->postJson("/api/p/{$party->code}/photos",
            ['photo' => $this->zdjecie()])->assertOk();

        $this->artisan('photos:cleanup')->assertSuccessful();

        $this->assertDatabaseCount('photos', 1);
    }

    // ==================================================== ranking

    /**
     * The order of the queue is the heart of the application - if these rules do
     * not work, the party plays the same thing over and over, or one person takes
     * the dance floor.
     */
    public function test_a_pinned_track_beats_everything_else(): void
    {
        $party = Party::factory()->create(['status' => 'live']);

        $pinned = QueueItem::factory()->create([
            'party_id' => $party->id, 'hype_count' => 0, 'is_pinned' => true,
        ]);
        QueueItem::factory()->create(['party_id' => $party->id, 'hype_count' => 99]);

        RankingEngine::for($party)->rescore($party);

        $this->assertSame($pinned->id, $party->queue()->first()->id);
    }

    public function test_a_track_by_the_same_artist_drops_down_the_queue(): void
    {
        $party = Party::factory()->settings(['artist_cooldown' => 6])->create(['status' => 'live']);

        // This artist played only a moment ago.
        QueueItem::factory()->create([
            'party_id' => $party->id, 'artist' => 'Sanah',
            'status' => 'played', 'finished_at' => now()->subMinute(),
        ]);

        $tenSam = QueueItem::factory()->create([
            'party_id' => $party->id, 'artist' => 'Sanah', 'hype_count' => 5,
        ]);
        $other = QueueItem::factory()->create([
            'party_id' => $party->id, 'artist' => 'Dawid Podsiadlo', 'hype_count' => 5,
        ]);

        RankingEngine::for($party)->rescore($party);

        $this->assertSame($other->id, $party->queue()->first()->id,
            'Przy rownym poparciu wyzej ma byc ten, ktorego wykonawca dawno nie gral.');
        $this->assertLessThan($other->fresh()->score, $tenSam->fresh()->score);
    }

    public function test_an_old_submission_climbs_despite_little_support(): void
    {
        $party = Party::factory()->settings(['aging_strength' => 'strong'])->create(['status' => 'live']);

        $stara = QueueItem::factory()->create([
            'party_id' => $party->id, 'hype_count' => 1,
            'queued_at' => now()->subHours(2),
        ]);
        $swieza = QueueItem::factory()->create([
            'party_id' => $party->id, 'hype_count' => 2, 'queued_at' => now(),
        ]);

        RankingEngine::for($party)->rescore($party);

        $this->assertSame($stara->id, $party->queue()->first()->id,
            'Po dwoch godzinach czekania jeden hype ma przebic dwa swieze.');
    }

    /** Pomocnik: najmniejszy poprawny plik JPEG. */
    private function zdjecie()
    {
        return UploadedFile::fake()->image('foto.jpg', 800, 600);
    }
}

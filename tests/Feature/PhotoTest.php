<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\PartyTestCase;

/**
 * Guests' photos.
 *
 * This is the first feature that handles the likeness of particular people -
 * that is, personal data. The access control cannot be a "probably works": the
 * photos lie outside public/ and every way in has to be checked.
 */
class PhotoTest extends PartyTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function zdjecie(): UploadedFile
    {
        return UploadedFile::fake()->image('party.jpg', 1600, 1200);
    }

    // ------------------------------------------------------------ wysylanie

    public function test_a_guest_sends_a_photo(): void
    {
        $party = Party::factory()->create();
        [$guest, $deviceId] = $this->guestFor($party);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/photos", ['photo' => $this->zdjecie()])
            ->assertOk()
            ->assertJson(['ok' => true, 'moderation' => false]);

        $photo = Photo::first();

        $this->assertNotNull($photo);
        $this->assertSame($guest->id, $photo->guest_id);
        $this->assertSame('visible', $photo->status);
        Storage::assertExists($photo->path());
    }

    public function test_sending_requires_joining_the_party(): void
    {
        $party = Party::factory()->create();

        $this->postJson("/api/p/{$party->code}/photos", ['photo' => $this->zdjecie()])
            ->assertStatus(403);
    }

    public function test_a_banned_guest_cannot_send(): void
    {
        $party = Party::factory()->create();
        [, $deviceId] = $this->guestFor($party, ['is_banned' => true]);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/photos", ['photo' => $this->zdjecie()])
            ->assertStatus(422);
    }

    public function test_disabling_photos_blocks_sending(): void
    {
        $party = Party::factory()->settings(['photos_enabled' => false])->create();
        [, $deviceId] = $this->guestFor($party);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/photos", ['photo' => $this->zdjecie()])
            ->assertStatus(422);
    }

    public function test_the_photo_limit_per_guest(): void
    {
        $party = Party::factory()->settings(['photo_max_per_guest' => 2])->create();
        [$guest, $deviceId] = $this->guestFor($party);

        Photo::factory()->count(2)->create(['party_id' => $party->id, 'guest_id' => $guest->id]);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/photos", ['photo' => $this->zdjecie()])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Wykorzystałeś swój limit 2 zdjęć na tej imprezie.');
    }

    public function test_files_that_are_not_images_are_rejected(): void
    {
        $party = Party::factory()->create();
        [, $deviceId] = $this->guestFor($party);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/photos", [
                'photo' => UploadedFile::fake()->create('wirus.exe', 100),
            ])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------ moderacja

    public function test_moderation_mode_holds_a_photo_back(): void
    {
        $party = Party::factory()->settings(['photo_moderation' => true])->create();
        [, $deviceId] = $this->guestFor($party);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/photos", ['photo' => $this->zdjecie()])
            ->assertOk()
            ->assertJson(['moderation' => true]);

        $this->assertSame('pending', Photo::first()->status);
    }

    public function test_a_wedding_moderates_photos_by_default(): void
    {
        $wesele = Party::factory()->wedding()->create();

        $this->assertTrue($wesele->settings()->bool('photo_moderation'),
            'Na weselu zdjecia nie moga trafiac na ekran bez zgody wodzireja');
    }

    public function test_an_unapproved_photo_stays_out_of_the_gallery(): void
    {
        $party = Party::factory()->create();
        [, $deviceId] = $this->guestFor($party);

        Photo::factory()->oczekuje()->create(['party_id' => $party->id]);
        Photo::factory()->create(['party_id' => $party->id]);

        $this->asGuest($deviceId)
            ->getJson("/api/p/{$party->code}/photos")
            ->assertOk()
            ->assertJsonCount(1, 'photos');
    }

    public function test_the_host_approves_a_photo(): void
    {
        $party = Party::factory()->create();
        $photo = Photo::factory()->oczekuje()->create(['party_id' => $party->id]);

        $this->actingAs($party->user)
            ->postJson("/host/{$party->code}/api/photos/{$photo->id}/approve")
            ->assertOk();

        $this->assertSame('visible', $photo->fresh()->status);
    }

    public function test_a_rejected_photo_disappears_from_disk(): void
    {
        $party = Party::factory()->create();
        [$guest, $deviceId] = $this->guestFor($party);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/photos", ['photo' => $this->zdjecie()]);

        $photo = Photo::first();
        $path = $photo->path();

        $this->actingAs($party->user)
            ->postJson("/host/{$party->code}/api/photos/{$photo->id}/reject")
            ->assertOk();

        // Keeping photos the host does not want has no justification.
        $this->assertDatabaseCount('photos', 0);
        Storage::assertMissing($path);
    }

    public function test_a_stranger_cannot_moderate_another_hosts_photos(): void
    {
        $stranger = User::factory()->create();
        $party = Party::factory()->create();
        $photo = Photo::factory()->oczekuje()->create(['party_id' => $party->id]);

        $this->actingAs($stranger)
            ->postJson("/host/{$party->code}/api/photos/{$photo->id}/approve")
            ->assertForbidden();
    }

    // ------------------------------------------------------------ usuwanie

    public function test_a_guest_removes_their_own_photo(): void
    {
        $party = Party::factory()->create();
        [$guest, $deviceId] = $this->guestFor($party);
        $photo = Photo::factory()->create(['party_id' => $party->id, 'guest_id' => $guest->id]);

        // The right to delete one's own likeness is a data-protection
        // requirement, not a courtesy to the guest.
        $this->asGuest($deviceId)
            ->deleteJson("/api/p/{$party->code}/photos/{$photo->id}")
            ->assertOk();

        $this->assertDatabaseCount('photos', 0);
    }

    public function test_a_guest_cannot_remove_someone_elses_photo(): void
    {
        $party = Party::factory()->create();
        [, $deviceId] = $this->guestFor($party);
        $obce = Photo::factory()->create(['party_id' => $party->id]);

        $this->asGuest($deviceId)
            ->deleteJson("/api/p/{$party->code}/photos/{$obce->id}")
            ->assertStatus(403);

        $this->assertDatabaseCount('photos', 1);
    }

    // ------------------------------------------------------------ dostep do pliku

    public function test_an_outsider_cannot_view_another_partys_photos(): void
    {
        $party = Party::factory()->create();
        $photo = Photo::factory()->create(['party_id' => $party->id]);

        // The party code alone is not enough - it is printed on cards on the
        // tables, so it is too poor a secret where people's likenesses go.
        $this->get("/z/{$party->code}/{$photo->id}")->assertForbidden();
    }

    public function test_the_screen_uses_a_signed_url(): void
    {
        $party = Party::factory()->settings(['photos_on_screen' => true])->create();
        [, $deviceId] = $this->guestFor($party);

        $this->asGuest($deviceId)->postJson("/api/p/{$party->code}/photos", ['photo' => $this->zdjecie()]);

        $url = $this->getJson("/api/screen/{$party->code}")->json('photos.0.url');

        $this->assertStringContainsString('signature=', $url);

        // The same address works with no cookie and no sign-in - the screen on
        // the television has neither.
        $this->get($url)->assertOk();
    }

    public function test_a_forged_signature_does_not_work(): void
    {
        $party = Party::factory()->create();
        $photo = Photo::factory()->create(['party_id' => $party->id]);

        $this->get("/z/{$party->code}/{$photo->id}?signature=cokolwiek&expires=9999999999")
            ->assertForbidden();
    }

    public function test_a_guest_of_the_party_can_view_a_photo(): void
    {
        $party = Party::factory()->create();
        [$guest, $deviceId] = $this->guestFor($party);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/photos", ['photo' => $this->zdjecie()]);

        $photo = Photo::first();

        $this->asGuest($deviceId)->get("/z/{$party->code}/{$photo->id}")->assertOk();
    }

    public function test_a_photo_from_another_party_gives_404(): void
    {
        $party = Party::factory()->create();
        $foreign = Party::factory()->create();
        [, $deviceId] = $this->guestFor($party);
        $photo = Photo::factory()->create(['party_id' => $foreign->id]);

        $this->asGuest($deviceId)->get("/z/{$party->code}/{$photo->id}")->assertNotFound();
    }

    // ------------------------------------------------------------ galeria

    public function test_a_closed_gallery_shows_only_your_own(): void
    {
        $party = Party::factory()->settings(['photos_visible_to_guests' => false])->create();
        [$guest, $deviceId] = $this->guestFor($party);

        Photo::factory()->create(['party_id' => $party->id, 'guest_id' => $guest->id]);
        Photo::factory()->count(3)->create(['party_id' => $party->id]);

        $this->asGuest($deviceId)
            ->getJson("/api/p/{$party->code}/photos")
            ->assertJsonCount(1, 'photos');
    }

    public function test_the_host_downloads_every_photo_as_a_zip(): void
    {
        $party = Party::factory()->create();
        [, $deviceId] = $this->guestFor($party);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/photos", ['photo' => $this->zdjecie()]);

        $this->actingAs($party->user)
            ->get("/host/{$party->code}/zdjecia.zip")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');
    }

    // ------------------------------------------------------------ sprzatanie

    /**
     * We promise the host that after a set time the photos will be gone. These
     * are particular people's likenesses, so the code has to keep that promise.
     */
    public function test_photos_disappear_after_the_retention_period(): void
    {
        $party = Party::factory()->settings(['photo_retention_days' => 30])->create([
            'status' => 'ended',
            'ended_at' => now()->subDays(31),
        ]);

        Photo::factory()->count(3)->create(['party_id' => $party->id]);

        $this->artisan('photos:cleanup')->assertSuccessful();

        $this->assertDatabaseCount('photos', 0);
    }

    public function test_fresh_photos_stay(): void
    {
        $party = Party::factory()->settings(['photo_retention_days' => 30])->create([
            'status' => 'ended',
            'ended_at' => now()->subDays(5),
        ]);

        Photo::factory()->count(3)->create(['party_id' => $party->id]);

        $this->artisan('photos:cleanup')->assertSuccessful();

        $this->assertDatabaseCount('photos', 3);
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $party = Party::factory()->settings(['photo_retention_days' => 7])->create([
            'status' => 'ended', 'ended_at' => now()->subDays(30),
        ]);

        Photo::factory()->count(2)->create(['party_id' => $party->id]);

        $this->artisan('photos:cleanup --dry-run')->assertSuccessful();

        $this->assertDatabaseCount('photos', 2);
    }

    public function test_cleanup_removes_the_files_from_disk(): void
    {
        $party = Party::factory()->settings(['photo_retention_days' => 1])->create();
        [, $deviceId] = $this->guestFor($party);

        // The photo has to be created while the party is still running - a
        // closed one accepts no submissions.
        $this->asGuest($deviceId)->postJson("/api/p/{$party->code}/photos", ['photo' => $this->zdjecie()]);
        $path = Photo::first()->path();

        $party->update(['status' => 'ended', 'ended_at' => now()->subDays(5)]);

        Storage::assertExists($path);

        $this->artisan('photos:cleanup');

        Storage::assertMissing($path);
    }

    public function test_a_party_with_no_end_date_counts_from_creation(): void
    {
        // An abandoned party that never started - its photos must not stay on
        // the server forever.
        $party = Party::factory()->settings(['photo_retention_days' => 10])->create([
            'status' => 'draft', 'starts_at' => null, 'ends_at' => null, 'ended_at' => null,
        ]);
        $party->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();

        Photo::factory()->create(['party_id' => $party->id]);

        $this->artisan('photos:cleanup');

        $this->assertDatabaseCount('photos', 0);
    }

    // ------------------------------------------------------------ sciana na ekranie

    public function test_the_screen_receives_photos_for_the_wall(): void
    {
        $party = Party::factory()->settings(['photos_on_screen' => true])->create();
        Photo::factory()->count(2)->create(['party_id' => $party->id]);
        Photo::factory()->oczekuje()->create(['party_id' => $party->id]);

        $this->getJson("/api/screen/{$party->code}")
            ->assertOk()
            ->assertJsonCount(2, 'photos');
    }

    public function test_a_disabled_wall_sends_no_photos_to_the_screen(): void
    {
        $party = Party::factory()->settings(['photos_on_screen' => false])->create();
        Photo::factory()->count(3)->create(['party_id' => $party->id]);

        $this->getJson("/api/screen/{$party->code}")->assertJsonCount(0, 'photos');
    }
}

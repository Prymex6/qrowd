<?php

namespace Tests\Feature;

use App\Models\ApiUsage;
use App\Models\CatalogTrack;
use App\Services\CatalogImporter;
use App\Services\YouTube\QuotaGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Filling the catalogue.
 *
 * Every mistake here costs YouTube quota that cannot be topped up - and the
 * catalogue takes weeks to build.
 */
class CatalogImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.youtube.key' => 'klucz-testowy']);
    }

    private function track(array $n = []): array
    {
        return array_merge([
            'youtube_id' => 'abcdefghijk',
            'title' => 'Chwile ulotne',
            'artist' => 'Sanah',
            'title_raw' => 'Sanah - Chwile ulotne',
            'channel' => 'sanah - Topic',
            'channel_id' => 'UC12345',
            'thumbnail' => null,
            'is_topic' => true,
            'view_count' => 1_200_000,
            'duration_seconds' => 210,
            'is_embeddable' => true,
        ], $n);
    }

    /**
     * A regression: store() wrote 'view_count' => $track['view_count'] ?? null,
     * so an answer with no statistics (the author hid the counter) wiped a number
     * we already had. After that, the command filling in metadata took the same
     * track on EVERY run and paid quota for it endlessly.
     */
    public function test_a_refresh_never_wipes_a_known_view_count(): void
    {
        $importer = CatalogImporter::make();

        $importer->store([$this->track()]);

        $this->assertSame(1_200_000, CatalogTrack::first()->view_count);

        // This time YouTube returned neither statistics nor a channel id.
        $importer->store([$this->track(['view_count' => null, 'channel_id' => null])]);

        $track = CatalogTrack::first();

        $this->assertSame(1_200_000, $track->view_count, 'Liczba odslon zostala skasowana.');
        $this->assertSame('UC12345', $track->channel_id, 'Identyfikator kanalu zostal skasowany.');
    }

    /** We always set the checked marker - finishing the job rests on it. */
    public function test_every_check_updates_the_checked_marker(): void
    {
        $importer = CatalogImporter::make();
        $importer->store([$this->track()]);

        CatalogTrack::first()->forceFill(['checked_at' => now()->subYear()])->save();

        $importer->store([$this->track(['view_count' => null])]);

        $this->assertTrue(CatalogTrack::first()->checked_at->isToday());
    }

    /** Refreshing the metadata does not change where a track came to us from. */
    public function test_a_refresh_does_not_rewrite_provenance(): void
    {
        $importer = CatalogImporter::make();
        $importer->store([$this->track()]);

        CatalogTrack::first()->forceFill(['source' => 'manual'])->save();

        $importer->store([$this->track()]);

        $this->assertSame('manual', CatalogTrack::first()->source);
    }

    public function test_the_genre_survives_an_import_that_omits_it(): void
    {
        $importer = CatalogImporter::make();

        $importer->store([$this->track()], 'disco-polo');
        $this->assertSame('disco-polo', CatalogTrack::first()->genre);

        $importer->store([$this->track()]);
        $this->assertSame('disco-polo', CatalogTrack::first()->genre);
    }

    // ==================================================== odsiewanie na wejsciu

    public function test_compilations_and_overlong_recordings_are_filtered_out(): void
    {
        $importer = CatalogImporter::make();

        $importer->store([
            $this->track(['youtube_id' => 'aaaaaaaaaaa', 'title' => 'Relaks 1 hour']),
            $this->track(['youtube_id' => 'bbbbbbbbbbb', 'title' => 'Disco polo skladanka']),
            $this->track(['youtube_id' => 'ccccccccccc', 'duration_seconds' => 30]),
            $this->track(['youtube_id' => 'ddddddddddd', 'duration_seconds' => 1800]),
            $this->track(['youtube_id' => 'eeeeeeeeeee', 'is_embeddable' => false]),
        ]);

        $this->assertSame(0, CatalogTrack::count());
    }

    /**
     * Tytuly z YouTube potrafia miec uszkodzone emoji - polowki par surogatow.
     * MySQL odrzuca takie dane i wywala caly, wielogodzinny import.
     */
    public function test_corrupt_text_does_not_break_the_import(): void
    {
        $importer = CatalogImporter::make();

        $importer->store([$this->track([
            'title' => "Chwile ulotne \xED\xA0\x80 remix",
            'artist' => "Sanah\xED\xA0\x80",
        ])]);

        $this->assertSame(1, CatalogTrack::count());
        $this->assertStringContainsString('Chwile ulotne', CatalogTrack::first()->title);
    }

    public function test_a_playlist_import_does_not_start_without_quota(): void
    {
        Http::fake();

        ApiUsage::create([
            'quota_date' => QuotaGuard::fromConfig()->quotaDate(),
            'operation' => 'search',
            'units' => 10000,
        ]);

        $result = CatalogImporter::make()->importPlaylist('PL123');

        Http::assertNothingSent();
        $this->assertSame(0, $result['added']);
    }
}

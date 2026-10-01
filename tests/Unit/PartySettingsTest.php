<?php

namespace Tests\Unit;

use App\Support\PartySettings;
use PHPUnit\Framework\TestCase;

class PartySettingsTest extends TestCase
{
    public function test_returns_values_default_when_nothing_not_saved(): void
    {
        $s = PartySettings::make(null);

        $this->assertSame(PartySettings::DEFAULTS['set_length'], $s->int('set_length'));
    }

    /**
     * Crucial for backward compatibility: a party saved before a new option was
     * added has to get a sensible value rather than null.
     */
    public function test_old_party_receives_new_options_from_default(): void
    {
        $s = PartySettings::make(['set_length' => 5]);

        $this->assertSame(5, $s->int('set_length'));
        $this->assertSame(PartySettings::DEFAULTS['crossfade_seconds'], $s->int('crossfade_seconds'));
    }

    public function test_preset_type_party_overwrites_default(): void
    {
        $wesele = PartySettings::make(null, 'wedding');

        $this->assertTrue($wesele->bool('moderation'), 'Wesele ma miec moderacje domyslnie');
        $this->assertTrue($wesele->bool('filter_explicit'));
    }

    public function test_saved_settings_beatą_preset(): void
    {
        $s = PartySettings::make(['moderation' => false], 'wedding');

        $this->assertFalse($s->bool('moderation'), 'Wybor hosta ma pierwszenstwo nad presetem');
    }

    public function test_house_party_allows_search_in_whole_youtube(): void
    {
        $this->assertFalse(PartySettings::make(null, 'houseparty')->bool('catalog_only'));
    }

    public function test_wedding_also_allows_search_in_youtube(): void
    {
        // The catalogue is a cache, not a cage. A guest at a wedding must be
        // able to find their track too, even when it is not in the database.
        $this->assertFalse(PartySettings::make(null, 'wedding')->bool('catalog_only'));
    }

    public function test_strength_ageing_przeklacan_on_points_on_minute(): void
    {
        $slabe = PartySettings::make(['aging_strength' => 'weak']);
        $mocne = PartySettings::make(['aging_strength' => 'strong']);

        $this->assertLessThan($mocne->agingPerMinute(), $slabe->agingPerMinute());
    }

    public function test_unknown_strength_ageing_drops_on_average(): void
    {
        $s = PartySettings::make(['aging_strength' => 'kosmiczna']);

        $this->assertSame(PartySettings::AGING['medium'], $s->agingPerMinute());
    }

    public function test_duration_playback_trims_to_limit(): void
    {
        $s = PartySettings::make(['max_track_seconds' => 240]);

        $this->assertSame(240, $s->playableSeconds(400), 'Dlugi utwor ma byc przyciety');
        $this->assertSame(180, $s->playableSeconds(180), 'Krotszy zostaje bez zmian');
    }

    public function test_mode_fast_ride_shortens_every_track(): void
    {
        $s = PartySettings::make([
            'fast_mode' => true, 'fast_mode_seconds' => 120, 'max_track_seconds' => 300,
        ]);

        $this->assertSame(120, $s->playableSeconds(280));
    }

    public function test_zero_duration_receives_limit_instead_of_zero(): void
    {
        // Tracks from the schedule have no known duration - they cannot play for 0 seconds.
        $s = PartySettings::make(['max_track_seconds' => 300]);

        $this->assertSame(300, $s->playableSeconds(0));
    }
}

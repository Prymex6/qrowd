<?php

namespace Tests\Unit;

use App\Support\TrackTitle;
use PHPUnit\Framework\TestCase;

/**
 * Tidying titles that come from YouTube.
 *
 * Raw titles are a mess ("BOYS - Wakacyjna milosc (Nowy Oficjalny Teledysk) HIT
 * Disco Polo 2024"). Without this step the search looks like a rubbish tip, and
 * the split into artist and title comes out at random.
 */
class TrackTitleTest extends TestCase
{
    public function test_splits_artist_and_title_after_dash(): void
    {
        [$artistName, $title] = TrackTitle::split('Zenek Martyniuk - Przez Twe Oczy Zielone');

        $this->assertSame('Zenek Martyniuk', $artistName);
        $this->assertSame('Przez Twe Oczy Zielone', $title);
    }

    public function test_handles_dash_long_and_en_dash(): void
    {
        foreach (['–', '—'] as $separator) {
            [$artistName, $title] = TrackTitle::split("Boys {$separator} Jestes Szalona");

            $this->assertSame('Boys', $artistName, "separator: {$separator}");
            $this->assertSame('Jestes Szalona', $title);
        }
    }

    public function test_strips_suffixes_in_brackets(): void
    {
        [, $title] = TrackTitle::split('Weekend - Ona Tanczy Dla Mnie (Official Video)');

        $this->assertSame('Ona Tanczy Dla Mnie', $title);
    }

    public function test_strips_polish_suffixes(): void
    {
        [, $title] = TrackTitle::split('Diament - Lala Malowana (oficjalny teledysk)');

        $this->assertSame('Lala Malowana', $title);
    }

    public function test_leaves_brackets_which_carry_information(): void
    {
        // "(Fair Play Remix)" is a different version of the track - it must not be cut.
        [, $title] = TrackTitle::split('Joker - Pierwszy Taniec (Fair Play Remix)');

        $this->assertStringContainsString('Fair Play Remix', $title);
    }

    public function test_strips_tail_from_suffixes_without_brackets(): void
    {
        [, $title] = TrackTitle::split('Tarzan Boy - Tarzan Disco Polo');

        $this->assertSame('Tarzan', $title);
    }

    public function test_removes_emoji_and_decorations(): void
    {
        [, $title] = TrackTitle::split('Daj To Glosniej - Zwariowana noc ┇Oficjalny Teledysk┇2020');

        $this->assertSame('Zwariowana noc', $title);
    }

    public function test_removes_topic_appended_to_artist(): void
    {
        [$artistName, $title] = TrackTitle::split('Piotr Cugowski - Topic - Takich Jak My Nie Znal Swiat');

        $this->assertSame('Piotr Cugowski', $artistName);
        $this->assertSame('Takich Jak My Nie Znal Swiat', $title);
    }

    public function test_trims_ad_channel_after_vertical_dash(): void
    {
        [, $title] = TrackTitle::split('Mega Disco - Kocham Cie Za Bardzo | NOWOSC 2026 HIT DISCO POLO');

        $this->assertSame('Kocham Cie Za Bardzo', $title);
    }

    public function test_not_trims_title_when_after_dash_is_content(): void
    {
        // A part with no such additions may belong to the title - then we leave it.
        [, $title] = TrackTitle::split('Ktos - Piosenka | Druga Czesc Tytulu');

        $this->assertStringContainsString('Druga Czesc Tytulu', $title);
    }

    public function test_converts_all_caps_on_normal_save(): void
    {
        [$artistName] = TrackTitle::split('PAWEL DOMAGALA - Wez nie pytaj');

        $this->assertSame('Pawel Domagala', $artistName);
    }

    public function test_leaves_short_shortcuts_capital_letters(): void
    {
        [$artistName] = TrackTitle::split('ONA - Kiedy powiem sobie dosc');

        $this->assertSame('ONA', $artistName);
    }

    public function test_without_dash_artist_is_name_channel(): void
    {
        [$artistName, $title] = TrackTitle::split('Jakas Piosenka', 'Kanal Muzyczny');

        $this->assertSame('Kanal Muzyczny', $artistName);
        $this->assertSame('Jakas Piosenka', $title);
    }

    public function test_clears_ending_topic_from_name_channel(): void
    {
        $this->assertSame('Dawid Podsiadlo', TrackTitle::cleanChannel('Dawid Podsiadlo - Topic'));
        $this->assertSame('Sanah', TrackTitle::cleanChannel('Sanah VEVO'));
    }

    public function test_not_reverses_artist_and_title_at_long_name(): void
    {
        // Wczesniejsza heurystyka odwracala czlony po dlugosci i psula
        // poprawne wpisy z dlugimi nazwami wykonawcow.
        [$artistName, $title] = TrackTitle::split(
            'Meskie Granie Orkiestra 2020 (Daria Zawialow, Igo, Krol) - Swit'
        );

        $this->assertStringStartsWith('Meskie Granie Orkiestra', $artistName);
        $this->assertSame('Swit', $title);
    }
}

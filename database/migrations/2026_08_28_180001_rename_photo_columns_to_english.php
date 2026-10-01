<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photo columns to English.
     *
     * The photos table was the last place carrying Polish column names.
     * Everything a developer touches is English now; only what the guest
     * and the host actually read on screen stays in Polish.
     */
    private const RENAMES = [
        'plik' => 'file',
        'miniatura' => 'thumbnail',
        'podpis' => 'caption',
        'rozmiar' => 'size',
        'szerokosc' => 'width',
        'wysokosc' => 'height',
        'polubienia' => 'likes',
        'pokazane_na_ekranie_at' => 'shown_on_screen_at',
    ];

    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            foreach (self::RENAMES as $from => $to) {
                $table->renameColumn($from, $to);
            }
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            foreach (self::RENAMES as $from => $to) {
                $table->renameColumn($to, $from);
            }
        });
    }
};

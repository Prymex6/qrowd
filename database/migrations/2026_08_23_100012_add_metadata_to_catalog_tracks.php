<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_tracks', function (Blueprint $table) {
            // The exact YouTube title, character for character. The tidied
            // version goes into the title/artist columns and serves the search,
            // but a guest has to be able to see the original to be sure it is THAT
            // song.
            $table->string('title_raw', 500)->nullable()->after('artist');
            $table->string('channel')->nullable()->after('title_raw');

            // We store the thumbnail address permanently, even though it can be
            // derived from the id. The reason: once we fetch the images to our own
            // side, this same column will point at a local file and nothing else
            // will need changing. The catalogue is to be ours, not dependent on
            // Google.
            $table->string('thumbnail_url', 500)->nullable()->after('channel');

            // Kanaly "- Topic" to automatycznie generowane wgrania od wytworni.
            // Zawieraja czysty dzwiek z plyty: bez mowionego intro, bez dialogow
            // z teledysku, bez outro z zapowiedzia kolejnego kawalka.
            $table->boolean('is_topic')->default(false)->after('channel');

            // Reczne przyciecie poczatku i konca konkretnego utworu -
            // ratunek na teledyski z gadanym wstepem.
            $table->unsignedSmallInteger('start_offset')->default(0)->after('duration_seconds');
            $table->unsignedSmallInteger('end_offset')->default(0)->after('start_offset');

            $table->index(['is_topic', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_tracks', function (Blueprint $table) {
            $table->dropIndex(['is_topic', 'is_active']);
            $table->dropColumn(['title_raw', 'channel', 'is_topic', 'start_offset', 'end_offset']);
        });
    }
};

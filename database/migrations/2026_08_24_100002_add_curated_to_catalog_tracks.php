<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_tracks', function (Blueprint $table) {
            // Whether the track comes from a curated playlist (wedding, feast,
            // party) or from a bulk dump of an artist's discography.
            //
            // The difference is decisive for AUTOMATIC picks: when the queue runs
            // dry, the system chooses by itself. Drawing from the whole catalogue
            // gave a wedding a children's cartoon song, and American explicit rap
            // right after it. Only tracks somebody deliberately put on a party
            // playlist are fit to be drawn.
            $table->boolean('is_curated')->default(false)->after('is_active');
            $table->index(['is_curated', 'genre']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_tracks', function (Blueprint $table) {
            $table->dropIndex(['is_curated', 'genre']);
            $table->dropColumn('is_curated');
        });
    }
};

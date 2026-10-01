<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_tracks', function (Blueprint $table) {
            // The YouTube view count - the only objective signal of popularity we
            // have. Without it the search put an unknown cover on a level with the
            // original, because textual relevance does not tell one from the
            // other.
            //
            // Fetching it costs nothing: videos.list is 1 unit per 50 tracks
            // whatever number of fields we pull out of it.
            $table->unsignedBigInteger('view_count')->nullable()->after('play_count');
            $table->index('view_count');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_tracks', function (Blueprint $table) {
            $table->dropIndex(['view_count']);
            $table->dropColumn('view_count');
        });
    }
};

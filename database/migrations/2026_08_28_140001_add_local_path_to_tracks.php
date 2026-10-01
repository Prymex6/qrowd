<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Playing from disk as an alternative to YouTube.
     *
     * A track from the host's folder gets a SYNTHETIC youtube_id (the prefix "L"
     * plus a hash of the path) rather than being allowed an empty field. That way
     * all the existing code keeps working unchanged: the repeat block, merging
     * duplicates in the queue and deduplication by id all still look at the same
     * field. Which way a track is played is decided solely by the presence of
     * local_path.
     */
    public function up(): void
    {
        Schema::table('catalog_tracks', function (Blueprint $table) {
            // A path RELATIVE to the folder from the settings - the disk can be
            // moved or the library relocated without rewriting the whole
            // catalogue.
            $table->string('local_path', 500)->nullable()->after('youtube_id');
            $table->index('local_path');
        });

        Schema::table('queue_items', function (Blueprint $table) {
            // We copy the path onto the queue entry, just as we do the title and
            // the artist - a party's history should outlive a reindexing of the
            // folder or a track being removed from the catalogue.
            $table->string('local_path', 500)->nullable()->after('youtube_id');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_tracks', function (Blueprint $table) {
            $table->dropIndex(['local_path']);
            $table->dropColumn('local_path');
        });

        Schema::table('queue_items', function (Blueprint $table) {
            $table->dropColumn('local_path');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The owner of a track from disk.
     *
     * The catalogue is shared across the whole service - and so it should be,
     * because tracks from YouTube are the same for everyone. But the disk library
     * is PRIVATE: those are one particular host's files, sitting on their laptop.
     *
     * Without this column a second host would see the first one's tracks in the
     * search and try to play files that are not on their computer.
     */
    public function up(): void
    {
        Schema::table('catalog_tracks', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('local_path')
                ->constrained()->cascadeOnDelete();

            $table->index(['user_id', 'local_path']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_tracks', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'local_path']);
            $table->dropColumn('user_id');
        });
    }
};

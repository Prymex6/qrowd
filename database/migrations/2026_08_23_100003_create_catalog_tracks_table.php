<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_tracks', function (Blueprint $table) {
            $table->id();

            $table->string('youtube_id', 20)->unique();
            $table->string('title');
            $table->string('artist')->nullable();

            $table->unsignedSmallInteger('duration_seconds')->default(0);
            $table->string('genre', 40)->nullable();

            $table->boolean('is_explicit')->default(false);
            $table->boolean('is_embeddable')->default(true);
            $table->boolean('is_wedding_safe')->default(true);
            $table->boolean('is_active')->default(true);

            // Rosnie z kazdym zagraniem - napedza podpowiedzi i playliste awaryjna.
            $table->unsignedInteger('play_count')->default(0);

            $table->enum('source', ['seed', 'youtube', 'manual'])->default('seed');
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'genre']);
            $table->index('play_count');
        });

        // Layer 1 of the search - it costs zero YouTube quota units. FULLTEXT
        // exists in MySQL/MariaDB alone. On another driver we skip the index, so
        // the migration does not fall over under SQLite, say.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE catalog_tracks ADD FULLTEXT catalog_search (title, artist)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_tracks');
    }
};

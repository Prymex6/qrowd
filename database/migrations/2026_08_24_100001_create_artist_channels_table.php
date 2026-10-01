<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_channels', function (Blueprint $table) {
            $table->id();

            // The artist's name as we searched for it (from the music folder).
            $table->string('name');
            $table->string('name_norm')->index();

            $table->string('channel_id', 40)->nullable();
            $table->string('channel_title')->nullable();
            $table->string('uploads_playlist', 60)->nullable();

            $table->enum('status', [
                'pending',    // czeka na wyszukanie
                'found',  // kanal znaleziony, czeka na import
                'imported',
                'none',        // nothing sensible was found
            ])->default('pending');

            $table->unsignedSmallInteger('imported_tracks')->default(0);
            $table->unsignedSmallInteger('unit_cost')->default(0);
            $table->timestamp('searched_at')->nullable();

            $table->timestamps();

            $table->unique('name_norm');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_channels');
    }
};

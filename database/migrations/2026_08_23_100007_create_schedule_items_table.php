<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();

            $table->time('at');
            $table->string('title');
            $table->string('screen_message')->nullable();

            $table->enum('action', [
                'play_track',   // zagraj konkretny utwor (pierwszy taniec, Sto lat)
                'announce',     // sam komunikat na ekranie
                'pause_queue',
                'resume_queue',
                'set_volume',
                'set_mode',
                'end_party',
            ])->default('play_track');

            $table->string('youtube_id', 20)->nullable();
            $table->string('track_title')->nullable();
            $table->string('track_artist')->nullable();

            $table->json('payload')->nullable();

            // A locked slot - guests can neither outbid nor move it.
            $table->boolean('is_locked')->default(true);
            $table->enum('status', ['pending', 'done', 'skipped'])->default('pending');
            $table->timestamp('fired_at')->nullable();

            $table->timestamps();

            $table->index(['party_id', 'status', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_items');
    }
};

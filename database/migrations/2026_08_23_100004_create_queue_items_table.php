<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('catalog_track_id')->nullable()->constrained()->nullOnDelete();

            // We copy the track's data, so the history outlives changes in the catalogue.
            $table->string('youtube_id', 20);
            $table->string('title');
            $table->string('artist')->nullable();
            $table->unsignedSmallInteger('duration_seconds')->default(0);

            $table->unsignedSmallInteger('hype_count')->default(0);

            // Worked out by RankingEngine: hype plus ageing, minus the penalties.
            $table->decimal('score', 10, 3)->default(0);

            $table->enum('status', [
                'pending',   // czeka na akceptacje hosta (tryb moderacji)
                'queued',    // czeka w kolejce
                'playing',   // gra teraz
                'played',    // zagrane
                'vetoed',    // wyrzucone przez hosta
                'skipped',   // pominiete automatycznie
                'expired',   // usuniete jako martwe (brak hype-ow)
            ])->default('queued');

            $table->boolean('is_pinned')->default(false);
            $table->enum('source', ['guest', 'host', 'auto', 'schedule'])->default('guest');

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['party_id', 'status', 'score']);
            $table->index(['party_id', 'youtube_id', 'status']);
            $table->index(['party_id', 'status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_items');
    }
};

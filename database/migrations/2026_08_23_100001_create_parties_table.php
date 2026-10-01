<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The code guests type in by hand or receive from the QR code.
            $table->string('code', 8)->unique();
            $table->string('slug')->nullable()->unique();
            $table->string('name');

            $table->enum('type', ['wedding', 'corporate', 'birthday', 'houseparty'])->default('houseparty');
            $table->enum('mode', ['democracy', 'mix', 'host_rules'])->default('mix');
            $table->enum('status', ['draft', 'scheduled', 'live', 'paused', 'ended'])->default('draft');
            $table->enum('plan', ['free', 'party', 'wedding', 'pro'])->default('free');

            $table->unsignedSmallInteger('max_guests')->default(25);

            // Token parowania urzadzenia grajacego (laptop / tablet przy barze).
            $table->string('player_token', 40)->nullable()->unique();
            $table->timestamp('player_seen_at')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            // The rhythm, queue and filter settings. Defaults in App\Support\PartySettings.
            $table->json('settings')->nullable();

            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};

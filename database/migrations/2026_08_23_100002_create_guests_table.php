<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();

            $table->string('nickname', 32);
            $table->string('avatar', 16)->default('star');

            // Identyfikator urzadzenia - podstawa limitow wrzutek i banow.
            $table->string('device_hash', 64);

            $table->boolean('is_banned')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['party_id', 'device_hash']);
            $table->index(['party_id', 'is_banned']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};

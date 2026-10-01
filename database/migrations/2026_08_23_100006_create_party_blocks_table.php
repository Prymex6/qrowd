<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();

            $table->enum('type', ['track', 'artist', 'genre', 'keyword']);
            $table->string('value');

            $table->timestamps();

            $table->index(['party_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_blocks');
    }
};

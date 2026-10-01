<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One hype per track per guest - guarded by the database, not by application code.
            $table->unique(['queue_item_id', 'guest_id']);
            $table->index('guest_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votes');
    }
};

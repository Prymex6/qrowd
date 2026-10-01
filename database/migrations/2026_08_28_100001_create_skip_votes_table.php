<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skip_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One vote per track per guest - guarded by the database, not by
            // application code. Without it, clicking faster than others would be
            // enough.
            $table->unique(['queue_item_id', 'guest_id']);
            $table->index('guest_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skip_votes');
    }
};

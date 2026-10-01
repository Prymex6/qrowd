<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_cache', function (Blueprint $table) {
            $table->id();

            // The cache is SHARED by every party - one wedding pays for a search
            // and the next two hundred get the result free.
            $table->string('query_hash', 64)->unique();
            $table->string('query');
            $table->json('results');
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('expires_at');

            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_cache');
    }
};

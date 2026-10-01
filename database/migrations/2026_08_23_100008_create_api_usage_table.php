<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_usage', function (Blueprint $table) {
            $table->id();

            // The YouTube quota day resets at 9 a.m. Polish time, not at midnight.
            $table->date('quota_date');

            $table->enum('operation', ['search', 'videos', 'playlist'])->default('search');
            $table->unsignedSmallInteger('units');
            $table->string('query')->nullable();
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['quota_date', 'operation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage');
    }
};

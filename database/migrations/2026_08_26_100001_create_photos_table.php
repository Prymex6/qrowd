<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();

            // The file's name on disk. The photos do NOT live in public/ - access
            // goes through a controller that checks whether the asker belongs to
            // the party. Otherwise guessing an address would be enough to watch
            // somebody else's wedding.
            $table->string('plik', 80);
            $table->string('miniatura', 80)->nullable();

            $table->string('podpis', 140)->nullable();
            $table->unsignedInteger('rozmiar')->default(0);
            $table->unsignedSmallInteger('szerokosc')->default(0);
            $table->unsignedSmallInteger('wysokosc')->default(0);

            $table->enum('status', [
                'pending',   // czeka na akceptacje hosta (tryb moderacji)
                'visible',
                'rejected',
            ])->default('visible');

            $table->unsignedSmallInteger('polubienia')->default(0);
            $table->timestamp('pokazane_na_ekranie_at')->nullable();

            $table->timestamps();

            $table->index(['party_id', 'status', 'created_at']);
            $table->index(['party_id', 'guest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};

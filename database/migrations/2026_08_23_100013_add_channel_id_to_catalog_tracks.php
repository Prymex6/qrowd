<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_tracks', function (Blueprint $table) {
            // Identyfikator kanalu pozwala jednym zapytaniem siegnac po CALA
            // dyskografie wykonawcy, zamiast zbierac go po jednym utworze
            // z przypadkowych skladanek.
            $table->string('channel_id', 40)->nullable()->after('channel');
            $table->index(['channel_id', 'is_topic']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_tracks', function (Blueprint $table) {
            $table->dropIndex(['channel_id', 'is_topic']);
            $table->dropColumn('channel_id');
        });
    }
};

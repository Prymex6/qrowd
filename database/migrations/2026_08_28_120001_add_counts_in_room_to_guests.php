<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether this guest is really somebody in the room.
     *
     * A host who opens the guest view on their laptop creates an ordinary guest -
     * nickname, cookie and all. Until now that put them into the denominator of
     * the skip vote and raised the threshold, though they are not a person on the
     * dance floor.
     */
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->boolean('counts_in_room')->default(true)->after('is_banned');
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('counts_in_room');
        });
    }
};

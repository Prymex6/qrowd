<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            // The moment the party was paused. It lets the time counter freeze
            // everywhere at once - the guests' phones, the screen on the TV and
            // the host's panel - and on resuming lets the track's start be pushed
            // by the length of the pause, so the progress bar still agrees with
            // what can be heard.
            $table->timestamp('paused_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn('paused_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The music folder belongs to the HOST, not to the party.
     *
     * One laptop means one library - the same files serve a wedding on Saturday
     * and a house party on Friday. Were the folder kept in a party's settings,
     * two parties with different folders would share one catalogue and the
     * relative paths from both would point at each other.
     *
     * A party chooses only the SOURCE (youtube / disk). Where exactly the files
     * come from is the host's business, and their computer's.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('music_folder', 500)->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('music_folder');
        });
    }
};

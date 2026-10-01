<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Polish values kept in the database, translated.
 *
 * The code already speaks English; these columns still held Polish words, and a
 * row written before this migration would never match a comparison written
 * after it. Enum columns cannot be updated in place - MySQL rejects a value
 * that is not yet on the list - so each one is widened to a plain string, the
 * rows are rewritten, and the enum is put back with the new list.
 */
return new class extends Migration
{
    private const STATUSES = [
        'photos' => [
            'kolumna' => 'status',
            'wartosci' => ['oczekuje' => 'pending', 'widoczne' => 'visible', 'odrzucone' => 'rejected'],
            'enum' => ['pending', 'visible', 'rejected'],
            'domyslna' => 'visible',
        ],
        'artist_channels' => [
            'kolumna' => 'status',
            'wartosci' => ['oczekuje' => 'pending', 'znaleziony' => 'found',
                'zaimportowany' => 'imported', 'brak' => 'none'],
            'enum' => ['pending', 'found', 'imported', 'none'],
            'domyslna' => 'pending',
        ],
    ];

    public function up(): void
    {
        foreach (self::STATUSES as $tabela => $opis) {
            if (! Schema::hasTable($tabela)) {
                continue;
            }

            DB::statement("ALTER TABLE `{$tabela}` MODIFY `{$opis['kolumna']}` VARCHAR(32) NOT NULL");

            foreach ($opis['wartosci'] as $stara => $nowa) {
                DB::table($tabela)->where($opis['kolumna'], $stara)->update([$opis['kolumna'] => $nowa]);
            }

            $lista = collect($opis['enum'])->map(fn ($w) => "'{$w}'")->implode(',');

            DB::statement(
                "ALTER TABLE `{$tabela}` MODIFY `{$opis['kolumna']}` "
                ."ENUM({$lista}) NOT NULL DEFAULT '{$opis['domyslna']}'"
            );
        }

        // Platnosci trzymaja status w zwyklej kolumnie tekstowej.
        if (Schema::hasTable('payments')) {
            foreach (['nowa' => 'new', 'oplacona' => 'paid', 'odrzucona' => 'rejected'] as $stara => $nowa) {
                DB::table('payments')->where('status', $stara)->update(['status' => $nowa]);
            }

            DB::statement("ALTER TABLE `payments` MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'new'");
        }

        if (Schema::hasTable('artist_channels') && Schema::hasColumn('artist_channels', 'koszt_jednostek')) {
            Schema::table('artist_channels', function ($table) {
                $table->renameColumn('koszt_jednostek', 'unit_cost');
            });
        }
    }

    public function down(): void
    {
        // Going back to the Polish values makes no sense - the code no longer understands them.
    }
};

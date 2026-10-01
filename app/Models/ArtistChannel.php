<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The queue of artists to be found on YouTube.
 *
 * Finding a channel costs 100 units, so it cannot be done in one run - the list
 * spreads across several days. This table holds the state, so each further run
 * knows where it stopped.
 */
class ArtistChannel extends Model
{
    protected $fillable = [
        'name', 'name_norm', 'channel_id', 'channel_title', 'uploads_playlist',
        'status', 'imported_tracks', 'unit_cost', 'searched_at',
    ];

    protected function casts(): array
    {
        return ['searched_at' => 'datetime'];
    }

    public static function norm(string $name): string
    {
        $t = mb_strtolower(trim($name), 'UTF-8');
        $t = strtr($t, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);
        $t = preg_replace('/[^a-z0-9 ]/u', ' ', $t) ?? $t;

        return trim(preg_replace('/\s+/', ' ', $t) ?? $t);
    }
}

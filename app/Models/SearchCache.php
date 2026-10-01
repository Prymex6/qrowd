<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchCache extends Model
{
    protected $table = 'search_cache';

    protected $fillable = ['query_hash', 'query', 'results', 'hits', 'expires_at'];

    protected function casts(): array
    {
        return [
            'results' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public static function hashFor(string $query): string
    {
        return hash('sha256', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query))));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartyBlock extends Model
{
    use HasFactory;

    protected $fillable = ['party_id', 'type', 'value'];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}

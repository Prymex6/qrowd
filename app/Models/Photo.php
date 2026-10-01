<?php

namespace App\Models;

use Database\Factories\PhotoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Photo extends Model
{
    use HasFactory;

    protected $fillable = [
        'party_id', 'guest_id', 'file', 'thumbnail', 'caption',
        'size', 'width', 'height', 'status', 'likes',
        'shown_on_screen_at',
    ];

    protected function casts(): array
    {
        return ['shown_on_screen_at' => 'datetime'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /** A party's photo directory. Outside public/ - reachable only through the controller. */
    public static function directory(Party $party): string
    {
        return 'imprezy/'.$party->code.'/zdjecia';
    }

    public function path(bool $thumb = false): string
    {
        $name = $thumb && $this->thumbnail ? $this->thumbnail : $this->file;

        return self::directory($this->party).'/'.$name;
    }

    /** Kasuje pliki z dysku razem z wpisem - inaczej zostalyby sieroty. */
    public function deleteWithFiles(): void
    {
        foreach ([$this->path(), $this->path(true)] as $path) {
            if (Storage::exists($path)) {
                Storage::delete($path);
            }
        }

        $this->delete();
    }

    protected static function newFactory(): PhotoFactory
    {
        return PhotoFactory::new();
    }
}

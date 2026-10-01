<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /**
     * The life of an order.
     *
     * 'new' covers both an order just opened and one the operator reports as
     * PENDING - in both cases the money has not landed and no package is due.
     */
    public const NEW = 'new';

    public const PAID = 'paid';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'order_id', 'user_id', 'party_id', 'plan', 'amount', 'status',
        'payment_id', 'secure', 'paid_at', 'notification',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'notification' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }
}

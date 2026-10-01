<?php

namespace Database\Factories;

use App\Models\Guest;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GuestFactory extends Factory
{
    protected $model = Guest::class;

    public function definition(): array
    {
        return [
            'party_id' => Party::factory(),
            'nickname' => fake()->firstName(),
            'avatar' => 'star',
            'device_hash' => hash('sha256', Str::random(20)),
            'is_banned' => false,
            'last_seen_at' => now(),
        ];
    }

    public function banned(): static
    {
        return $this->state(['is_banned' => true]);
    }
}

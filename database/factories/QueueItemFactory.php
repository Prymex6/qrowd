<?php

namespace Database\Factories;

use App\Models\Party;
use App\Models\QueueItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class QueueItemFactory extends Factory
{
    protected $model = QueueItem::class;

    public function definition(): array
    {
        return [
            'party_id' => Party::factory(),
            'guest_id' => null,
            'youtube_id' => Str::random(11),
            'title' => fake()->words(3, true),
            'artist' => fake()->name(),
            'duration_seconds' => 200,
            'hype_count' => 0,
            'score' => 0,
            'status' => 'queued',
            'source' => 'guest',
            'queued_at' => now(),
        ];
    }

    public function playing(): static
    {
        return $this->state(['status' => 'playing', 'started_at' => now()]);
    }

    public function played(): static
    {
        return $this->state([
            'status' => 'played',
            'started_at' => now()->subMinutes(4),
            'finished_at' => now(),
        ]);
    }
}

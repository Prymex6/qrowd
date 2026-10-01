<?php

namespace Database\Factories;

use App\Models\CatalogTrack;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CatalogTrackFactory extends Factory
{
    protected $model = CatalogTrack::class;

    public function definition(): array
    {
        return [
            'youtube_id' => Str::random(11),
            'title' => fake()->words(3, true),
            'artist' => fake()->name(),
            'duration_seconds' => fake()->numberBetween(120, 300),
            'genre' => 'test',
            'is_explicit' => false,
            'is_embeddable' => true,
            'is_wedding_safe' => true,
            'is_active' => true,
            'play_count' => 0,
            'source' => 'seed',
        ];
    }

    /** A track from a curated playlist - only these reach the automatic picks. */
    public function curated(string $genre = 'wesele'): static
    {
        return $this->state(['is_curated' => true, 'genre' => $genre]);
    }

    public function explicit(): static
    {
        return $this->state(['is_explicit' => true]);
    }

    public function notEmbeddable(): static
    {
        return $this->state(['is_embeddable' => false]);
    }
}

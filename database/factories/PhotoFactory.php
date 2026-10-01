<?php

namespace Database\Factories;

use App\Models\Guest;
use App\Models\Party;
use App\Models\Photo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PhotoFactory extends Factory
{
    protected $model = Photo::class;

    public function definition(): array
    {
        $name = Str::uuid().'.jpg';

        return [
            'party_id' => Party::factory(),
            'guest_id' => Guest::factory(),
            'file' => $name,
            'thumbnail' => 'mini-'.$name,
            'caption' => null,
            'size' => 280_000,
            'width' => 1600,
            'height' => 1200,
            'status' => 'visible',
        ];
    }

    public function oczekuje(): static
    {
        return $this->state(['status' => 'pending']);
    }
}

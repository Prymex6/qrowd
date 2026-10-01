<?php

namespace Database\Factories;

use App\Models\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PartyFactory extends Factory
{
    protected $model = Party::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'code' => Party::generateCode(),
            'name' => 'Impreza testowa',
            'type' => 'houseparty',
            'mode' => 'mix',
            'status' => 'live',
            'plan' => 'free',
            'max_guests' => 100,
            'player_token' => Party::generatePlayerToken(),
            'starts_at' => now(),
            'settings' => [],
        ];
    }

    public function wedding(): static
    {
        return $this->state(['type' => 'wedding', 'plan' => 'wedding']);
    }

    public function ended(): static
    {
        return $this->state(['status' => 'ended', 'ended_at' => now()]);
    }

    /** Overrides individual settings without losing the rest. */
    public function settings(array $settings): static
    {
        return $this->state(fn (array $attrs) => [
            'settings' => array_merge($attrs['settings'] ?? [], $settings),
        ]);
    }
}

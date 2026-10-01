<?php

namespace App\Console\Commands;

use App\Http\Controllers\Host\PlanController;
use App\Models\Party;
use Illuminate\Console\Command;

class SetPartyPlan extends Command
{
    protected $signature = 'qrowd:plan {code : Party code} {package : free|party|wedding|pro}';

    protected $description = 'Sets a party package by hand (for testing, and before payments go live)';

    public function handle(): int
    {
        $party = Party::where('code', strtoupper($this->argument('code')))->first();

        if (! $party) {
            $this->error('There is no party with the code '.$this->argument('code'));

            return self::FAILURE;
        }

        $id = $this->argument('package');

        if (! isset(PlanController::PACKAGES[$id])) {
            $this->error('Unknown package: '.$id);

            return self::FAILURE;
        }

        $package = PlanController::PACKAGES[$id];
        $party->update(['plan' => $id, 'max_guests' => $package['max_guests']]);

        $this->info("  {$party->name}: package {$package['name']}, up to {$package['max_guests']} guests");

        return self::SUCCESS;
    }
}

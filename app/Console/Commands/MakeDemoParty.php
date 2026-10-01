<?php

namespace App\Console\Commands;

use App\Models\Party;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class MakeDemoParty extends Command
{
    protected $signature = 'qrowd:party
                            {name=Wesele Ani i Kuby : Party name}
                            {--type=wedding : wedding|corporate|birthday|houseparty}';

    protected $description = 'Creates a test party and prints all three links';

    public function handle(): int
    {
        $user = User::firstOrCreate(
            ['email' => 'host@qrowd.test'],
            ['name' => 'Organizator', 'password' => Hash::make('haslo123')]
        );

        $party = Party::create([
            'user_id' => $user->id,
            'code' => Party::generateCode(),
            'name' => $this->argument('name'),
            'type' => $this->option('type'),
            'status' => 'live',
            'plan' => 'wedding',
            'max_guests' => 200,
            'player_token' => Party::generatePlayerToken(),
            'starts_at' => now(),
        ]);

        // Moderation is on by default at a wedding, which gets in the way here.
        $party->updateSettings(['moderation' => false]);

        $this->newLine();
        $this->info('  Party created: '.$party->name);
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>KOD:</> <options=bold>'.$party->code.'</>');
        $this->newLine();
        $this->line('  <fg=gray>Telefon goscia </>  '.$party->joinUrl());
        $this->line('  <fg=gray>Ekran na TV    </>  '.$party->screenUrl());
        $this->line('  <fg=gray>Odtwarzacz     </>  '.url('/player/'.$party->code.'?token='.$party->player_token));
        $this->newLine();
        $this->line('  <fg=gray>Konto hosta: host@qrowd.test / haslo123</>');
        $this->newLine();

        return self::SUCCESS;
    }
}

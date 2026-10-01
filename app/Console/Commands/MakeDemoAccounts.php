<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Accounts for clicking through the application on localhost.
 *
 * The passwords are deliberately simple and in the open - this is a development
 * environment. In production this command has no right to run at all, hence the
 * guard on APP_ENV.
 */
class MakeDemoAccounts extends Command
{
    protected $signature = 'qrowd:accounts {--password=haslo123 : Password for every demo account}';

    protected $description = 'Creates demonstration accounts (locally only)';

    private const KONTA = [
        [
            'email' => 'admin@qrowd.test',
            'name' => 'Bartek (admin)',
            'is_admin' => true,
            'plan' => 'pro',
            'description' => 'pełny dostęp + panel administratora',
        ],
        [
            'email' => 'host@qrowd.test',
            'name' => 'Organizator',
            'is_admin' => false,
            'plan' => 'free',
            'description' => 'zwykły organizator, bez panelu admina',
        ],
        [
            'email' => 'wodzirej@qrowd.test',
            'name' => 'Wodzirej Marek',
            'is_admin' => false,
            'plan' => 'pro',
            'description' => 'pakiet PRO, nielimitowane imprezy',
        ],
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('  The demonstration accounts do not run in production.');

            return self::FAILURE;
        }

        $haslo = (string) $this->option('password');

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>KONTA DO LOGOWANIA</>');
        $this->newLine();

        foreach (self::KONTA as $konto) {
            $user = User::updateOrCreate(
                ['email' => $konto['email']],
                [
                    'name' => $konto['name'],
                    'password' => Hash::make($haslo),
                ]
            );

            // is_admin and plan are deliberately NOT in the User model's
            // $fillable. Were they, adding is_admin=1 to the registration form
            // would be enough to make oneself an administrator. Here we set them
            // explicitly, going around mass assignment.
            $user->forceFill([
                'is_admin' => $konto['is_admin'],
                'plan' => $konto['plan'],
            ])->save();

            $this->line(sprintf(
                '  <fg=green>%-22s</> <options=bold>%s</>',
                $konto['email'],
                $haslo
            ));
            $this->line(sprintf('  %-22s <fg=gray>%s</>', '', $konto['description']));
            $this->newLine();
        }

        $this->line('  <fg=gray>Logowanie: '.url('/logowanie').'</>');
        $this->newLine();

        return self::SUCCESS;
    }
}

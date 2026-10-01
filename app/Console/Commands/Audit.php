<?php

namespace App\Console\Commands;

use App\Support\PartySettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;

/**
 * An audit of the application's consistency.
 *
 * It checks the things an ordinary test cannot catch, because they do not
 * concern one function but whether the whole holds together: whether every
 * setting does anything at all, whether every panel tab has content, and whether
 * any host action forgot to check who owns the party.
 *
 * Run it after any larger change:  php artisan qrowd:audit
 */
class Audit extends Command
{
    protected $signature = 'qrowd:audit';

    protected $description = 'Checks the consistency of the settings, the panel and the access control';

    private int $issues = 0;

    private int $serious = 0;

    public function handle(): int
    {
        $this->environment();
        $this->settingsCheck();
        $this->panel();
        $this->accessCheck();

        $this->newLine();

        if ($this->issues === 0) {
            $this->info('Nothing to report.');

            return self::SUCCESS;
        }

        // Yellow marks a deliberate gap - the setting works, there is simply
        // nothing to click it with. Red marks something broken, and only red
        // fails the audit.
        $this->line("  Findings: {$this->issues}, of which serious: {$this->serious}");

        return $this->serious > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ------------------------------------------------------------ environment

    /**
     * Whether an application exposed on a public address is running in
     * development mode.
     *
     * With APP_ENV=local, Inertia registers /_inertia/devtools/* - an endpoint
     * that hands over a log of every request WITH NO SIGN-IN AT ALL. Signed photo
     * addresses landed in that log together with valid signatures, so one request
     * went around the whole protection of the gallery. APP_DEBUG, in turn, shows
     * the contents of .env on the error page, and the API key is in there.
     */
    private function environment(): void
    {
        $this->components->info('Environment');

        $url = (string) config('app.url');

        $public = $url !== ''
            && ! preg_match('~^https?://(localhost|127\.0\.0\.1|\[::1\])(:|/|$)~i', $url);

        if (! $public) {
            return;
        }

        if (app()->environment('local')) {
            $this->reportIssue('APP_ENV', 'local mode on a public address - /_inertia/devtools/* is wide open', true);
        }

        if (config('app.debug')) {
            $this->reportIssue('APP_DEBUG', 'switched on at a public address - the error page shows .env', true);
        }

        // The API key must never reach the frontend bundle.
        foreach (['VITE_YOUTUBE_KEY', 'VITE_YOUTUBE_API_KEY'] as $variable) {
            if (filled(env($variable))) {
                $this->reportIssue($variable, 'the YouTube key is exposed to the browser', true);
            }
        }

        $bundles = glob(public_path('build/assets/*.js')) ?: [];

        foreach ($bundles as $batch) {
            if (preg_match('/AIza[0-9A-Za-z_\-]{30,}/', (string) file_get_contents($batch))) {
                $this->reportIssue(basename($batch), 'an API key inside a built frontend file', true);
            }
        }
    }

    // ------------------------------------------------------------ ustawienia

    private function settingsCheck(): void
    {
        $this->components->info('Party settings');

        $code = $this->applicationCode();
        $validation = file_get_contents(app_path('Http/Controllers/Host/SettingsController.php'));
        $panel = file_get_contents(resource_path('js/Pages/Host/Settings.vue'));

        foreach (array_keys(PartySettings::DEFAULTS) as $key) {
            $missing = [];

            // A dead key is worse than a missing feature: the panel saves it,
            // the host sees the change, and the party behaves exactly as before.
            if (! str_contains($code, "'{$key}'")) {
                $missing[] = 'READ NOWHERE';
            }
            if (! str_contains($validation, "'{$key}'")) {
                $missing[] = 'no validation';
            }
            if (! str_contains($panel, $key)) {
                $missing[] = 'no control in the panel';
            }

            if ($missing) {
                $this->reportIssue($key, implode(' | ', $missing),
                    in_array('READ NOWHERE', $missing, true));
            }
        }

        foreach (PartySettings::PRESETS as $name => $preset) {
            foreach (array_keys($preset) as $key) {
                if (! array_key_exists($key, PartySettings::DEFAULTS)) {
                    $this->reportIssue("preset {$name}", "the key '{$key}' does not exist", true);
                }
            }
        }
    }

    // ------------------------------------------------------------ panel

    private function panel(): void
    {
        $this->components->info('The settings panel');

        $panel = file_get_contents(resource_path('js/Pages/Host/Settings.vue'));

        preg_match_all("/section === '([a-z]+)'/", $panel, $blocks);
        preg_match_all("/\['([a-z]+)'\s*,\s*'([^']+)'\]/", $panel, $tabs, PREG_SET_ORDER);

        $maBlok = array_unique($blocks[1]);

        foreach ($tabs as $z) {
            if (! in_array($z[1], $maBlok, true)) {
                // A tab with no block of its own falls into the v-else and shows
                // somebody else's content - which is exactly what happened to the
                // "Filtry treści" section.
                $this->reportIssue($z[2], 'a tab with no section of its own', true);
            }
        }

        // The same value behind two controls means two sliders overwriting each
        // other.
        preg_match_all('/v-model(?:\.number)?="form\.([a-z_]+)"/', $panel, $models);

        foreach (array_count_values($models[1]) as $key => $count) {
            if ($count > 1) {
                $this->reportIssue("form.{$key}", "bound to {$count} controls", true);
            }
        }
    }

    // ------------------------------------------------------------ dostep

    private function accessCheck(): void
    {
        $this->components->info('Access control');

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                continue;
            }

            $r = new ReflectionMethod($class, $method);

            $takesParty = collect($r->getParameters())->contains(
                fn ($p) => $p->getType() && str_contains((string) $p->getType(), 'Party')
            );

            if (! $takesParty) {
                continue;
            }

            $body = $this->bodyWithHelpers($class, $method);
            $path = $route->uri();

            // A party is addressed by a six-character code, so being signed in
            // is not enough - without an ownership check any host knowing that
            // code would be steering somebody else's party.
            if (str_starts_with($path, 'host/')
                && ! preg_match('/authorizeParty|autoryzuj|authorize\(|user_id/', $body)) {
                $this->reportIssue($path, 'a host action with no ownership check', true);
            }

            if (str_starts_with($path, 'api/player/') && ! str_contains($body, 'assertToken')) {
                $this->reportIssue($path, 'a player action with no token', true);
            }
        }
    }

    // ------------------------------------------------------------ pomocnicze

    /** The method body plus the helpers it calls - checks often live in those. */
    private function bodyWithHelpers(string $class, string $method): string
    {
        $wez = function (string $m) use ($class): string {
            $r = new ReflectionMethod($class, $m);

            if (! $r->getFileName()) {
                return '';
            }

            return implode('', array_slice(
                file($r->getFileName()),
                $r->getStartLine() - 1,
                $r->getEndLine() - $r->getStartLine() + 1
            ));
        };

        $body = $wez($method);

        if (preg_match_all('/\$this->([a-zA-Z]+)\(/', $body, $called)) {
            foreach (array_unique($called[1]) as $helper) {
                if ($helper !== $method && method_exists($class, $helper)) {
                    $body .= $wez($helper);
                }
            }
        }

        return $body;
    }

    /** Kod, w ktorym szukamy realnych odczytow ustawien. */
    private function applicationCode(): string
    {
        $code = '';

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // A validation rule is not a reading of the setting.
            if (str_ends_with($file->getPathname(), 'SettingsController.php')) {
                continue;
            }

            $body = file_get_contents($file->getPathname());

            // From the defaults we take the methods alone, because the array
            // itself would look like a use of every key.
            if (str_ends_with($file->getPathname(), 'PartySettings.php')) {
                $body = substr($body, strpos($body, 'public function __construct'));
            }

            $code .= $body;
        }

        foreach (array_merge(
            glob(resource_path('js/Pages/*/*.vue')),
            glob(resource_path('js/Components/*.vue')),
        ) as $view) {
            $code .= file_get_contents($view);
        }

        return $code;
    }

    private function reportIssue(string $what, string $description, bool $serious = false): void
    {
        $this->issues++;

        if ($serious) {
            $this->serious++;
        }

        $this->line(sprintf('  <fg=%s>%s</> %-28s %s',
            $serious ? 'red' : 'yellow',
            $serious ? '✖' : '!',
            $what,
            $description
        ));
    }
}

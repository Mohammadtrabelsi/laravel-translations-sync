<?php

namespace Mohammadtrabelsi\TranslationSync;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Mohammadtrabelsi\TranslationSync\Console\Commands\SyncTranslationKeys;

class TranslationSyncServiceProvider extends ServiceProvider
{
    /**
     * Register the package's console command.
     *
     * The command is only useful when running under the console, so it is
     * registered inside the console guard to keep it out of web requests.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncTranslationKeys::class,
            ]);

            $this->warnWhenOverridden();
        }
    }

    /**
     * Warn when another command (e.g. an old copy left in app/Console/Commands)
     * registers the same "translations:sync" name and runs instead of the
     * package command.
     */
    private function warnWhenOverridden(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if ($event->command !== 'translations:sync') {
                return;
            }

            $command = Artisan::all()['translations:sync'] ?? null;

            if ($command !== null && ! $command instanceof SyncTranslationKeys) {
                $event->output->writeln('');
                $event->output->writeln('  <fg=yellow>⚠ translations:sync is overridden by '.$command::class.'.</>');
                $event->output->writeln('  <fg=yellow>  The package command is not running. Delete that class to use it.</>');
            }
        });
    }
}

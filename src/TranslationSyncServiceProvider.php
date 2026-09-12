<?php

namespace RizqEngine\TranslationSync;

use Illuminate\Support\ServiceProvider;
use RizqEngine\TranslationSync\Console\Commands\SyncTranslationKeys;

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
        }
    }
}

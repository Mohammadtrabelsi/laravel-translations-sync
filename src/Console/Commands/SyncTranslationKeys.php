<?php

namespace Mohammadtrabelsi\TranslationSync\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

class SyncTranslationKeys extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Scans resources/, Livewire component classes & blade views, app/Services,
     * and every module under the laravel-modules Modules/ tree.
     *
     * php artisan translations:sync
     *   --locales=en,fr,ar   Override which locales to sync (default: auto-detect)
     *   --base=en            The reference locale to use as the source of truth
     *   --dry-run            Show what would change without writing files
     *   --clean              Also remove keys that no longer exist in views
     */
    protected $signature = 'translations:sync
                            {--locales= : Comma-separated list of locales to sync (e.g. en,fr,ar)}
                            {--base=en  : Base/reference locale}
                            {--dry-run  : Show changes without writing files}
                            {--clean    : Remove keys from files that are not found in views}';

    protected $description = 'Scan resources/, Livewire components & views, app/Services, and the Modules/ tree for translation keys, create missing lang files, and sync keys across all locales.';

    // ── Patterns ──────────────────────────────────────────────────────────────
    // Matches: __('key'), __("key"), trans('key'), trans("key"),
    //          @lang('key'), @lang("key"), trans_choice('key', …)
    // Also handles dot-notation groups: __('auth.failed'), __('validation.required')
    private array $patterns = [
        '/__\(\s*[\'"]([^\'"]+)[\'"]/U',
        '/trans\(\s*[\'"]([^\'"]+)[\'"]/U',
        '/@lang\(\s*[\'"]([^\'"]+)[\'"]/U',
        '/trans_choice\(\s*[\'"]([^\'"]+)[\'"]/U',
    ];

    public function handle(): int
    {
        $isDry = $this->option('dry-run');
        $doClean = $this->option('clean');
        $base = trim($this->option('base') ?? 'en');
        $langPath = lang_path();                    // resources/lang  (Laravel 9+)
        $resPath = resource_path();

        $this->info('');
        $this->line('  <fg=cyan>Laravel Translation Sync</>');
        $this->line('  '.str_repeat('─', 50));

        // ── 1. Collect all translation keys from views / PHP resources ──────
        $this->line("\n  📂 Scanning: <fg=yellow>{$resPath}</>");
        $foundKeys = $this->extractKeysFromDirectory($resPath);

        // Also scan Livewire components (classes + blade views), app/Services, and
        // every laravel-modules module. Livewire and module paths are resolved from
        // their own config so the command keeps working when those paths are customised.
        $extraPaths = array_merge(
            $this->livewireScanPaths(),
            $this->moduleScanPaths(),
            [app_path('Services')]
        );

        foreach ($extraPaths as $path) {
            if ($path !== '' && File::isDirectory($path)) {
                $this->line("  📂 Scanning: <fg=yellow>{$path}</>");
                $foundKeys = array_values(array_unique(array_merge($foundKeys, $this->extractKeysFromDirectory($path))));
            }
        }

        if ($foundKeys === []) {
            $this->warn('  No translation keys found. Make sure you use __(), trans(), or @lang().');

            return self::SUCCESS;
        }

        $this->line('  <fg=green>✔</> Found <fg=white>'.count($foundKeys).'</> unique key(s).');

        // ── 2. Group keys by their file/group prefix ─────────────────────────
        // e.g. "auth.failed" → group "auth", key "failed"
        //      "Hello world"  → group "strings" (ungrouped strings), key "Hello world"
        $grouped = $this->groupKeys($foundKeys);

        // ── 3. Determine locales ─────────────────────────────────────────────
        $locales = $this->resolveLocales($langPath, $base);
        $this->line('  <fg=green>✔</> Locales: <fg=white>'.implode(', ', $locales).'</>');

        // ── 4. Sync each locale ──────────────────────────────────────────────
        $this->line('');
        $stats = ['created' => 0, 'added' => 0, 'removed' => 0, 'unchanged' => 0];

        foreach ($locales as $locale) {
            $this->line("  🌍 <fg=cyan>{$locale}</>");
            $localePath = $langPath.DIRECTORY_SEPARATOR.$locale;

            if (! File::isDirectory($localePath)) {
                if ($isDry) {
                    $this->line("     <fg=yellow>[dry-run]</> Would create directory: {$localePath}");
                } else {
                    File::makeDirectory($localePath, 0755, true);
                    $this->line('     <fg=green>+</> Created directory.');
                }
                $stats['created']++;
            }

            foreach ($grouped as $group => $keys) {
                $filePath = $localePath.DIRECTORY_SEPARATOR.$group.'.php';
                $this->syncFile($filePath, $group, $keys, $locale, $base, $langPath, $isDry, $doClean, $stats);
            }
        }

        // ── 5. Summary ───────────────────────────────────────────────────────
        $this->line('');
        $this->line('  '.str_repeat('─', 50));
        $this->line("  <fg=green>Directories created :</> {$stats['created']}");
        $this->line("  <fg=green>Keys added          :</> {$stats['added']}");
        $this->line("  <fg=yellow>Keys removed        :</> {$stats['removed']}");
        $this->line("  <fg=white>Files unchanged     :</> {$stats['unchanged']}");
        $this->line('');

        if ($isDry) {
            $this->warn('  ⚠  Dry-run mode — no files were written.');
        } else {
            $this->info('  ✅ Translation files are up to date.');
        }

        return self::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Key extraction
    // ──────────────────────────────────────────────────────────────────────────

    private function extractKeysFromDirectory(string $directory): array
    {
        $allKeys = [];

        $files = File::allFiles($directory);

        foreach ($files as $file) {
            // Accept PHP source and Blade templates (e.g. Livewire component classes
            // and `*.blade.php` views). Both end in ".php".
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $content = File::get($file->getPathname());
            foreach ($this->patterns as $pattern) {
                if (preg_match_all($pattern, $content, $matches)) {
                    foreach ($matches[1] as $key) {
                        $allKeys[$key] = true;
                    }
                }
            }
        }

        return array_values(array_filter(array_keys($allKeys), fn (string $k): bool => $k !== ''));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Resolve the directories that hold Livewire components (classes + views)
    // ──────────────────────────────────────────────────────────────────────────

    private function livewireScanPaths(): array
    {
        // Livewire component classes — derived from the configured class namespace
        // (defaults to "App\Livewire"). The leading "App" root maps to the app/ path.
        $namespace = trim((string) config('livewire.class_namespace', 'App\\Livewire'), '\\');

        if ($namespace === 'App' || str_starts_with($namespace, 'App\\')) {
            $relative = trim(substr($namespace, strlen('App')), '\\');
            $classPath = $relative === ''
                ? app_path()
                : app_path(str_replace('\\', DIRECTORY_SEPARATOR, $relative));
        } else {
            $classPath = base_path(str_replace('\\', DIRECTORY_SEPARATOR, $namespace));
        }

        // Livewire component blade views — from the configured view path
        // (defaults to resources/views/livewire).
        $viewPath = (string) config('livewire.view_path', resource_path('views'.DIRECTORY_SEPARATOR.'livewire'));

        return [$classPath, $viewPath];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Resolve each module directory under the laravel-modules Modules/ tree
    // ──────────────────────────────────────────────────────────────────────────

    private function moduleScanPaths(): array
    {
        // The modules root is resolved from laravel-modules' own config
        // (defaults to base_path('Modules')) so a customised path still works.
        $root = (string) config('modules.paths.modules', base_path('Modules'));

        if ($root === '' || ! File::isDirectory($root)) {
            return [];
        }

        // Return each module directory individually so the scan is logged per
        // module. extractKeysFromDirectory() recurses, so this covers every
        // module's PHP classes (controllers, services, entities, …) and its
        // Resources/views blade templates.
        return array_map(fn ($dir): string => (string) $dir, File::directories($root));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Group keys by file prefix
    // ──────────────────────────────────────────────────────────────────────────

    private function groupKeys(array $keys): array
    {
        $grouped = [];

        foreach ($keys as $key) {
            // If it contains a dot, and the part before the first dot has no whitespace,
            // we assume it's a "group.key" notation.
            if (str_contains((string) $key, '.') && ! preg_match('/\s/', explode('.', (string) $key, 2)[0])) {
                [$group, $subKey] = explode('.', (string) $key, 2);
            } else {
                // Flat strings without a namespace go into "strings.php"
                $group = 'strings';
                $subKey = $key;
            }

            // Skip keys that produce an empty group or an empty sub-key to
            // avoid writing entries like '' => '[TODO] ...' into the file.
            if ($group === '' || $subKey === '') {
                continue;
            }

            // Skip partial keys built at runtime, e.g. __('admin.fields.'.$name)
            // is captured as "admin.fields." — it has an empty nested segment.
            if ($this->isNested($group, $subKey) && in_array('', explode('.', $subKey), true)) {
                continue;
            }

            $grouped[$group][$subKey] = null; // null = placeholder
        }

        return $grouped;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Sync a single translation file
    // ──────────────────────────────────────────────────────────────────────────

    private function syncFile(
        string $filePath,
        string $group,
        array $expectedKeys,   // ['key' => null, …]
        string $locale,
        string $base,
        string $langPath,
        bool $isDry,
        bool $doClean,
        array &$stats
    ): void {
        // Load existing translations for this locale
        $existing = File::exists($filePath)
            ? (include $filePath)   // returns array
            : [];

        if (! is_array($existing)) {
            $existing = [];
        }

        // Load base locale as fallback values (so non-base files have a hint)
        $baseFile = $langPath.DIRECTORY_SEPARATOR.$base.DIRECTORY_SEPARATOR.$group.'.php';
        $baseValues = ($locale !== $base && File::exists($baseFile))
            ? (include $baseFile)
            : [];
        if (! is_array($baseValues)) {
            $baseValues = [];
        }

        $added = [];
        $removed = [];

        // Fold flat dotted entries (e.g. 'fields.name' => '…', written by older
        // versions) into their nested form, so 'fields' => ['name' => '…'] is used.
        $existing = $this->foldDottedKeys($group, $existing, $removed);

        // Add missing keys
        foreach (array_keys($expectedKeys) as $key) {
            if ($this->hasKey($group, $existing, (string) $key)) {
                continue;
            }

            if ($this->isNested($group, (string) $key) && $this->blockedByScalar($existing, (string) $key)) {
                $this->warn("     ⚠ Skipped {$group}.{$key}: a parent key already holds a string value.");

                continue;
            }

            // For the base locale use the (last segment of the) key, replacing underscores with
            // spaces and capitalizing the first letter.
            // For other locales, use the base translation wrapped in [TODO].
            $label = $this->isNested($group, (string) $key) ? Arr::last(explode('.', (string) $key)) : (string) $key;
            $transformedKey = ucfirst(str_replace('_', ' ', $label));
            $baseValue = Arr::get($baseValues, $key);
            $placeholder = $locale === $base
                ? $transformedKey
                : ('[TODO] '.(is_string($baseValue) ? $baseValue : $transformedKey));

            if ($this->isNested($group, (string) $key)) {
                Arr::set($existing, $key, $placeholder);
            } else {
                $existing[$key] = $placeholder;
            }
            $added[] = $key;
        }

        // Optionally remove stale keys
        if ($doClean) {
            $existing = $this->removeStaleKeys($existing, $expectedKeys, '', $removed);
        }

        if ($added === [] && $removed === []) {
            $stats['unchanged']++;

            return;
        }

        // Report changes
        foreach ($added as $k) {
            $verb = File::exists($filePath) ? '<fg=green>+</>' : '<fg=green>✚</>';
            $this->line("     {$verb} {$group}.{$k}");
            $stats['added']++;
        }
        foreach ($removed as $k) {
            $this->line("     <fg=red>-</> {$group}.{$k}");
            $stats['removed']++;
        }

        if ($isDry) {
            return;
        }

        // Sort keys for clean diffs
        $this->sortRecursive($existing);

        // Write file
        $content = "<?php\n\nreturn ".$this->arrayToString($existing).";\n";

        if (! File::exists($filePath)) {
            $stats['created']++;
        }

        File::put($filePath, $content);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Nested key helpers
    // ──────────────────────────────────────────────────────────────────────────

    // Dotted sub-keys are stored as nested arrays ("fields.name" →
    // 'fields' => ['name' => …]), matching how Laravel resolves them. The
    // "strings" group and sub-keys containing whitespace hold full sentences,
    // so they stay flat.
    private function isNested(string $group, string $key): bool
    {
        return $group !== 'strings' && str_contains($key, '.') && ! preg_match('/\s/', $key);
    }

    private function hasKey(string $group, array $existing, string $key): bool
    {
        if (array_key_exists($key, $existing)) {
            return true;
        }

        return $this->isNested($group, $key) && Arr::has($existing, $key);
    }

    // True when a parent segment of $key is already a string, so nesting
    // under it would overwrite an existing translation.
    private function blockedByScalar(array $existing, string $key): bool
    {
        $node = $existing;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($node)) {
                return true;
            }
            if (! array_key_exists($segment, $node)) {
                return false;
            }
            $node = $node[$segment];
        }

        return false;
    }

    private function foldDottedKeys(string $group, array $existing, array &$removed): array
    {
        foreach ($existing as $key => $value) {
            if (! is_string($key) || ! $this->isNested($group, $key)) {
                continue;
            }

            unset($existing[$key]);

            if (Arr::has($existing, $key)) {
                // The nested entry already exists — the flat one is a duplicate.
                $removed[] = $key;
            } elseif ($this->blockedByScalar($existing, $key)) {
                $existing[$key] = $value; // keep as-is rather than lose a value
            } else {
                Arr::set($existing, $key, $value);
            }
        }

        return $existing;
    }

    private function removeStaleKeys(array $existing, array $expectedKeys, string $prefix, array &$removed): array
    {
        foreach ($existing as $key => $value) {
            $path = $prefix.$key;

            // Keep the entry if it is used directly (this also keeps whole
            // sub-arrays fetched at once, e.g. __('office.fields')).
            if (array_key_exists($path, $expectedKeys)) {
                continue;
            }

            if (is_array($value)) {
                $value = $this->removeStaleKeys($value, $expectedKeys, $path.'.', $removed);
                if ($value === []) {
                    unset($existing[$key]);
                } else {
                    $existing[$key] = $value;
                }

                continue;
            }

            unset($existing[$key]);
            $removed[] = $path;
        }

        return $existing;
    }

    private function sortRecursive(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Resolve which locales to process
    // ──────────────────────────────────────────────────────────────────────────

    private function resolveLocales(string $langPath, string $base): array
    {
        $option = $this->option('locales');

        if ($option) {
            $locales = array_map(trim(...), explode(',', $option));
        } elseif (File::isDirectory($langPath)) {
            // Auto-detect from existing directories
            $locales = array_map(
                basename(...),
                array_filter(File::directories($langPath), fn ($d): bool => ! str_starts_with(basename((string) $d), '_'))
            );
        } else {
            $locales = [];
        }

        // Always include the base locale
        if (! in_array($base, $locales)) {
            array_unshift($locales, $base);
        }

        sort($locales);

        return array_unique($locales);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Pretty-print a PHP array (avoids var_export ugliness)
    // ──────────────────────────────────────────────────────────────────────────

    private function arrayToString(array $array, int $indent = 1): string
    {
        $pad = str_repeat('    ', $indent);
        $lines = ['['];

        foreach ($array as $key => $value) {
            $k = is_string($key) ? "'{$this->escapeString($key)}'" : $key;

            if (is_array($value)) {
                $lines[] = $pad."{$k} => ".$this->arrayToString($value, $indent + 1).',';
            } else {
                $v = is_null($value)
                    ? 'null'
                    : (is_bool($value) ? ($value ? 'true' : 'false') : "'{$this->escapeString((string) $value)}'");
                $lines[] = $pad."{$k} => {$v},";
            }
        }

        $closePad = str_repeat('    ', $indent - 1);
        $lines[] = $closePad.']';

        return implode("\n", $lines);
    }

    private function escapeString(string $str): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $str);
    }
}

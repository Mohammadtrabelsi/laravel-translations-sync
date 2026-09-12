# Laravel Translation Sync

Scan your entire Laravel application for translation keys and keep every locale
in sync with a single artisan command.

It walks your `resources/`, Livewire component classes and blade views,
`app/Services`, and every module under a
[laravel-modules](https://github.com/nWidart/laravel-modules) `Modules/` tree,
extracts every key used with `__()`, `trans()`, `@lang()` or `trans_choice()`,
creates any missing `lang/{locale}/*.php` files, and adds the missing keys to
every locale so nothing is left untranslated.

## Requirements

- PHP `^8.2`
- Laravel 11, 12 or 13

## Installation

```bash
composer require rizqengine/laravel-translation-sync
```

The service provider is auto-discovered, so there is nothing else to register.

## Usage

```bash
php artisan translations:sync
```

### Options

| Option              | Description                                                        |
| ------------------- | ------------------------------------------------------------------ |
| `--locales=en,fr,ar`| Comma-separated locales to sync. Defaults to auto-detecting the `lang/` sub-directories. |
| `--base=en`         | The reference locale used as the source of truth (default `en`).   |
| `--dry-run`         | Show what would change without writing any files.                  |
| `--clean`           | Also remove keys from lang files that no longer appear in the code. |

### Examples

```bash
# Preview the changes without touching any files
php artisan translations:sync --dry-run

# Sync a specific set of locales, using French as the base
php artisan translations:sync --locales=fr,en,ar --base=fr

# Sync and prune keys that were deleted from the code
php artisan translations:sync --clean
```

## How keys are grouped

Keys are grouped by their file prefix:

- `__('auth.failed')` → `lang/{locale}/auth.php` under the `failed` key.
- `__('Hello world')` (a plain string with no group) → `lang/{locale}/strings.php`.

For the base locale a missing key gets a human-friendly placeholder derived from
the key itself (`welcome_back` → `Welcome back`). For other locales the missing
value is prefixed with `[TODO]` and seeded from the base translation so you can
easily find what still needs translating.

## License

The MIT License (MIT). See [LICENSE](LICENSE).

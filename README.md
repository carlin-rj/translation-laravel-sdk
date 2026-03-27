# translation-laravel-sdk

Laravel SDK for translation collect/sync, supporting Laravel 8/9/10/11/12:
- active collection (scan code keys/text),
- passive collection (missing key buffer + batch flush),
- system-config-driven package sync (auto locales + cursor incremental).
- takeover Laravel translator with module-aware `tc(...)` helper.

## Install
1. Add package to composer.
2. Publish config:
```bash
php artisan vendor:publish --tag=translation-sdk-config
```
3. Read full integration doc: `docs/USAGE.md`

## Commands
- `php artisan translation-sdk:collect-active`
- `php artisan translation-sdk:flush-missing`
- `php artisan translation-sdk:sync-package`

`sync-package` defaults:
- auto fetch target locales from translation gateway (`/interact/translation/sync-targets`)
- no `--module` means sync all modules
- use persisted cursor per `locale + module(或ALL)` for incremental sync
- `--full` to force full sync from cursor `0`
- `--cursor` to one-time override cursor (debug; no cursor persistence update)

For `collect-active`, `--module` acts as fallback module.
When scanner finds `tc(...)` with module argument, item module comes from `tc(...)`.

## Tests
- run unit tests (independent package mode):
```bash
composer install
composer test
```
- Composer constraints:
  - `illuminate/*`: `^8.83|^9.0|^10.0|^11.0|^12.0`
  - `orchestra/testbench` (dev): `^6.23|^7.0|^8.0|^9.0|^10.0`
- current suite:
  - clients: `TranslationGatewayClient`
  - services: `ModuleResolver`, `PassiveCollector`, `SdkTranslator`, `TranslationCacheRepository`, `MissingFlushService`, `PackageSyncService`, `ActiveCollector`, `RedisMissingBuffer`
  - scanner: `FileKeyScanner`
  - console commands: `collect-active`, `flush-missing`, `sync-package`

## Runtime Translate
- native Laravel entry:
```php
__('validation.required');
trans('order.status.pending');
```
- module-aware helper:
```php
tc('order.status.pending', ['name' => 'Tom'], module: 'order');
```

Unified runtime flow:
- `__()` / `trans()` / `trans_choice()` / Validator messages
- `tc()` with the same parameters as `__()`, plus `module`
- local Laravel lang first, then SDK cache
- on miss: return Laravel-style key fallback and write the missing item into passive buffer
- for key-like values such as `order.status.pending`, `source_text` prefers the default local lang text
- for plain text such as `你好 :name`, `source_text` keeps the original text
- passive collect has cooldown window (default `600s`) to avoid repeated reports.
- passive Redis buffer uses bucketed hash/queue to reduce big-key and blocking risk in high traffic.

## Global Scan
- command: `php artisan translation-sdk:collect-active`
- scans configured paths globally for `__`, `trans`, `trans_choice`, `@lang`, `Lang::*`, `tc`
- keys without explicit `module` use `translation_sdk.default_module`

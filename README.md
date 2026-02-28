# Laravel Ledger

[![Tests](https://github.com/gbxnga/laravel-ledger/actions/workflows/tests.yml/badge.svg)](https://github.com/gbxnga/laravel-ledger/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/gbxnga/laravel-ledger.svg)](https://packagist.org/packages/gbxnga/laravel-ledger)
[![PHP Version](https://img.shields.io/packagist/php-v/gbxnga/laravel-ledger.svg)](https://packagist.org/packages/gbxnga/laravel-ledger)
[![License](https://img.shields.io/packagist/l/gbxnga/laravel-ledger.svg)](https://packagist.org/packages/gbxnga/laravel-ledger)

General Ledger and Journal Accounting Package for Laravel with JSON API.

A maintained fork of [abivia/ledger](https://github.com/abivia/ledger), upgraded to support modern PHP and Laravel versions.

## Features

- Full double-entry accounting system with audit trail capability.
- Multi-currency support.
- Support for multiple business units.
- Sub-journal support.
- Multilingual.
- Integrates via direct controller access or through JSON API.
- Atomic transactions with concurrent update blocking.
- Reference system supports soft linking to other ERP components.
- Bulk journal detail inserts and aggregated balance updates for high-volume posting.
- Automatic batch coalescing — consecutive `entry/add` and `entry/delete` operations in a batch are merged into bulk passes.
- Configurable chunk sizes and optional performance metrics logging.

## Requirements

- PHP 8.2+
- Laravel 11 or 12

## Installation

```bash
composer require gbxnga/laravel-ledger
```

Publish configuration:

```bash
php artisan vendor:publish --provider="Abivia\Ledger\LedgerServiceProvider"
```

Create database tables:

```bash
php artisan migrate
```

## Migrating from abivia/ledger

If you're currently using `abivia/ledger`, you can switch to this package with minimal effort. All PHP namespaces (`Abivia\Ledger\*`) remain unchanged, so your application code works as-is.

1. Swap the package:

```bash
composer remove abivia/ledger
composer require gbxnga/laravel-ledger
```

2. Update your PHP and Laravel versions if needed:
   - PHP 8.2+ (previously 8.0+)
   - Laravel 11 or 12 (previously Laravel 8+)

Your published config (`config/ledger.php`), migrations, and database tables are not affected by the package swap. No changes are needed to your service provider references, config files, migrations, or any code that uses `Abivia\Ledger` classes.

## Configuration

The configuration file is installed as `config/ledger.php`. You can enable/disable the JSON API, set middleware, and a path prefix to the API.

### Performance Tuning

Journal detail inserts and balance updates are performed in bulk using chunked queries. When a batch contains consecutive `entry/add` or `entry/delete` operations, they are automatically coalesced into grouped passes — reducing database round-trips from O(n) per detail line to O(1) chunked operations.

All settings live under `ledger.performance` in `config/ledger.php` and can be overridden via environment variables:

| Environment Variable | Default | Description |
|---|---|---|
| `LEDGER_ENTRY_DETAIL_CHUNK` | `1000` | Max rows per `journal_details` bulk insert |
| `LEDGER_ENTRY_BALANCE_CHUNK` | `500` | Max rows per `ledger_balances` select/upsert pass |
| `LEDGER_ROOT_DETAIL_CHUNK` | `1000` | Max rows per opening-balance detail insert |
| `LEDGER_ROOT_BALANCE_CHUNK` | `500` | Max rows per opening-balance upsert |
| `LEDGER_BATCH_COALESCE_ENTRY_ADD` | `true` | Merge consecutive `entry/add` batch operations into one bulk write |
| `LEDGER_BATCH_COALESCE_ENTRY_DELETE` | `true` | Merge consecutive `entry/delete` batch operations into one bulk delete/reversal pass |
| `LEDGER_BATCH_COALESCE_MIN_GROUP` | `2` | Minimum consecutive add/delete operations required before coalescing activates |
| `LEDGER_PERFORMANCE_METRICS` | `false` | Emit structured performance logs (detail rows, chunks, elapsed time) |

## Updating

To ensure schema changes are in place, publish the configuration again and migrate:

```bash
php artisan vendor:publish --provider="Abivia\Ledger\LedgerServiceProvider"
php artisan migrate
```

## Testing

```bash
composer test
```

With coverage:

```bash
composer test-coverage
```

## Documentation

Full documentation is available at [ledger.abivia.com](https://ledger.abivia.com/).

## Credits

- [Alan Langford](https://github.com/abivia) - Original author
- [Gbenga Oni](https://github.com/gbxnga) - Maintainer of this fork

## License

MIT. See [LICENSE](LICENSE.md) for details.

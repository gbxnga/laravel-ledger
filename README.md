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

Performance chunk settings are configurable in `ledger.performance`:

- `LEDGER_ENTRY_DETAIL_CHUNK` (default `1000`)
- `LEDGER_ENTRY_BALANCE_CHUNK` (default `500`)
- `LEDGER_ROOT_DETAIL_CHUNK` (default `1000`)
- `LEDGER_ROOT_BALANCE_CHUNK` (default `500`)
- `LEDGER_BATCH_COALESCE_ENTRY_ADD` (default `true`)
- `LEDGER_BATCH_COALESCE_MIN_GROUP` (default `2`)
- `LEDGER_PERFORMANCE_METRICS` (default `false`)

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

# hotelpos

Greenfield PHP 8.x/MySQL implementation of the hotelpos SRS.

## What is included

- Object-oriented PHP app skeleton with PSR-like autoloading.
- PDO database wrapper and prepared-statement helpers.
- JSON API router with consistent success/error envelopes.
- Session authentication, role guards, CSRF protection, and idle timeout support.
- Service-layer billing, booking, payment, stock, audit, and report logic.
- Bootstrap/AJAX frontend at `public/index.php`.
- Versioned SQL migrations in `migrations/`.
- Noon-cutoff billing test in `tests/BillingServiceTest.php`.

The legacy app under `POS_v1/_review_tivoli/tivoli` is untouched and remains a reference only.

## Setup

1. Create a MySQL database, for example `hotelpos`.
2. Copy `config/config.example.php` to `config/config.local.php` and update database credentials.
3. Run migrations:

```powershell
C:\xampp\php\php.exe tools\migrate.php
```

4. Open the app through XAMPP/Apache:

```text
http://localhost/hotelpos/public/index.php
```

5. Create the first administrator locally. For example:

```powershell
C:\xampp\php\php.exe tools\create_admin.php --email admin@example.com --name "System Administrator"
```

## Tests

```powershell
C:\xampp\php\php.exe tests\BillingServiceTest.php
```

Run PHP syntax checks:

```powershell
Get-ChildItem -Recurse -Include *.php -Path app,public,tools,tests | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
```

## Notes

- Financial records use void fields instead of hard deletes.
- Bookings store `rate_per_night` at check-in to preserve historical prices.
- Extras copy `unit_price` at sale time.
- Checkout uses one centralized noon-cutoff billing rule.
- Legacy import guidance is in `tools/legacy_import_notes.md`.


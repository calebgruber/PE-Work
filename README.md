# PE Work

Pure PHP starter for theatre shop orders using the CG-Internal UI shell.

## What is included

- CG-Internal visual shell copied into `/shared/assets/style.css` and `/shared/assets/app.js`
- migration-backed starter schema for:
  - shows + required contacts
  - inventory categories/items
  - initial orders + revisions
  - global pull rules
  - export layout settings
- core pages:
  - `/setup` to apply migrations
  - `/` dashboard
  - `/show` show + revision editor
  - `/settings` inventory, rules, layout, and migration tabs
  - `/export` print-friendly order / spare / return views

## Local development

This app now expects MySQL for normal runtime use, including local development. Create a `config.local.php` file in the project root with values similar to:

```php
<?php
define('DB_DRIVER', 'mysql');
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'pe_work');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
define('APP_BASE_URL', '');
```

Then run:

```bash
php -S 127.0.0.1:8000 router.php
```

Then open:

- `http://127.0.0.1:8000/setup` to apply the starter migration
- `http://127.0.0.1:8000/` to use the app

## cPanel / MySQL deployment

Create a `config.local.php` file in the project root with values similar to:

```php
<?php
define('DB_DRIVER', 'mysql');
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'pe_work');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
define('APP_BASE_URL', '/your-cpanel-folder');
```

After updating code from GitHub, open **Settings → Migrations** (or `/setup`) and click **Apply Pending Migrations** to run any new files in `db/migrations/`.

SQLite remains available only for automated test runs when explicitly enabled by the test harness.

## CSV inventory import

Upload a CSV exported from Excel with these headers:

```text
category,name,shop_quantity,unit,default_note,description
```

## Current starter scope

This starter already supports:

- required show contacts and optional date/address/image fields
- initial shop orders and saved revisions
- per-item rent, spares, total, notes, and item-specific pull/return dates
- color-coded revision actions (add / return / exchange / see notes)
- live rule warnings for accessory planning inside the revision editor
- print-friendly export views that can be saved to PDF from the browser

The drag-and-drop paperwork editor mentioned in the issue is not fully built yet, but the starter now includes a migration-backed layout settings tab so that feature can grow without reworking the database foundation.
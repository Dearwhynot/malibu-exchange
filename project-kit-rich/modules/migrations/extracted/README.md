# Malibu migration module

This folder is an extracted, theme-friendly migration module based on the working Doverka approach:

- PHP callbacks are registered by key.
- Every migration or patch runs only once.
- Applied items are tracked in one history table.
- No UI is required.
- No Doverka business tables are included.

## Files

- `inc/migration-runner.php` - runner, registry loading, history tracking, execution.
- `inc/migrations/*.php` - schema migrations.
- `inc/seeders/*.php` - data patches / seeders.

The `migrations/` and `seeders/` directories may be empty until Malibu-specific items are added.

## How to connect it in Malibu Exchange

Add this line to the Malibu theme `functions.php`:

```php
require_once get_template_directory() . '/inc/migration-runner.php';
```

After that, pending migrations and patches run automatically on the next WordPress request through `init`.

## What runs and in what order

1. The runner creates the history table if it does not exist.
2. It loads `inc/migrations/*.php` in natural filename order.
3. It runs only items that are missing in history.
4. If all migrations succeed, it loads and runs `inc/seeders/*.php`.
5. Each applied item is saved to the history table once.

If two files resolve to the same normalized key, the runner stops with a clear error instead of silently overwriting one item.

If a callback completes but the history row cannot be written, the runner stores a pending safeguard marker and blocks automatic re-run for that item until the issue is resolved.

Default history table name:

```php
$wpdb->prefix . 'malibu_migration_history'
```

You can override it with the `malibu_migrations_table_name` filter.

## How to add a new migration

Create a new file, for example:

`inc/migrations/002_add_status_column.php`

File shape:

```php
<?php

if (!defined('ABSPATH')) {
	exit;
}

return [
	'key' => '002_add_status_column',
	'title' => 'Add status column',
	'description' => 'Adds a status column to a custom table.',
	'callback' => static function () {
		global $wpdb;

		$sql = "ALTER TABLE {$wpdb->prefix}some_table ADD COLUMN status varchar(32) NOT NULL DEFAULT 'new'";
		$result = $wpdb->query($sql);
		if ($result === false) {
			return new WP_Error('migration_failed', $wpdb->last_error);
		}

		return [
			'summary' => 'Status column added.',
			'messages' => ['Table updated successfully.'],
		];
	},
];
```

## How to add a new data patch

Create a new file, for example:

`inc/seeders/002_fill_default_statuses.php`

Use the same return structure. The callback should insert, update, or delete data.

## Manual control

The module auto-runs by default. You can disable auto-run:

```php
add_filter('malibu_migrations_autorun', '__return_false');
```

Then run manually:

```php
malibu_migrations_run_all();
```

## Notes

- This extracted module does not include Malibu demo schema or demo seed data.
- Add only project-specific migrations and seeders when Malibu integration starts.

# Malibu error-log module

This folder is a переносимый module for WordPress admin viewing of the standard PHP or WordPress `error_log` sink.

- It reads the existing `wp-content/debug.log` file by default.
- It does not create custom log files.
- It keeps two viewer versions.
- Default version is `v2`, because that is the active Doverka default today.

## Files

- `inc/error-log.php` - module bootstrap, admin page registration, shared logic.
- `inc/error-log/viewer-v1.php` - plain raw viewer.
- `inc/error-log/viewer-v2.php` - enhanced Doverka-style viewer with severity and source badges.

## How to connect it in Malibu Exchange

Add this line to the Malibu theme `functions.php`:

```php
require_once get_template_directory() . '/inc/error-log.php';
```

After that, an `Error Log` page appears in WordPress admin.

## Default behavior

- Viewer version: `v2`
- Log file path: `WP_CONTENT_DIR . '/debug.log'`
- Capability: `manage_options`

## How to switch version

Set the constant before admin page rendering:

```php
define('MALIBU_ERROR_LOG_VERSION', 'v1');
```

Or use a filter:

```php
add_filter('malibu_error_log_version', static function () {
	return 'v1';
});
```

Accepted values are `v1`, `v2`, `1`, and `2`.

## What the versions mean

- `v1` - raw log viewer without badges.
- `v2` - Doverka-compatible enhanced viewer with severity and source tagging.

## Optional overrides

Change log file path:

```php
add_filter('malibu_error_log_file_path', static function () {
	return WP_CONTENT_DIR . '/debug.log';
});
```

Change menu title:

```php
add_filter('malibu_error_log_menu_title', static function () {
	return 'Debug Log';
});
```

## How it works

1. The module adds one WordPress admin page.
2. It reads the log file in pages.
3. It supports search by substring.
4. It can clear the file from the admin page.
5. `v2` can add severity and source badges to each line.

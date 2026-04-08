<?php

if (!defined('ABSPATH')) {
	exit;
}

if (!function_exists('malibu_migrations_base_dir')) {
	function malibu_migrations_base_dir()
	{
		return __DIR__;
	}
}

if (!function_exists('malibu_migrations_table_name')) {
	function malibu_migrations_table_name()
	{
		global $wpdb;

		$default = $wpdb->prefix . 'malibu_migration_history';

		return (string) apply_filters('malibu_migrations_table_name', $default);
	}
}

if (!function_exists('malibu_migrations_lock_key')) {
	function malibu_migrations_lock_key()
	{
		return 'malibu_migrations_running';
	}
}

if (!function_exists('malibu_migrations_autorun_enabled')) {
	function malibu_migrations_autorun_enabled()
	{
		return (bool) apply_filters('malibu_migrations_autorun', true);
	}
}

if (!function_exists('malibu_migrations_normalize_key')) {
	function malibu_migrations_normalize_key($value)
	{
		$value = strtolower((string) $value);
		$value = preg_replace('/[^a-z0-9_-]+/', '_', $value);
		$value = trim((string) $value, '_');

		return is_string($value) ? $value : '';
	}
}

if (!function_exists('malibu_migrations_collect_messages')) {
	function malibu_migrations_collect_messages($messages)
	{
		if (!is_array($messages)) {
			$messages = [$messages];
		}

		$normalized = [];
		foreach ($messages as $message) {
			$message = trim((string) $message);
			if ($message === '') {
				continue;
			}

			$normalized[] = $message;
		}

		return $normalized;
	}
}

if (!function_exists('malibu_migrations_format_output_text')) {
	function malibu_migrations_format_output_text($summary, $messages = [])
	{
		$lines = [];
		$summary = trim((string) $summary);
		if ($summary !== '') {
			$lines[] = $summary;
		}

		foreach (malibu_migrations_collect_messages($messages) as $message) {
			$lines[] = '- ' . $message;
		}

		return trim(implode("\n", $lines));
	}
}

if (!function_exists('malibu_migrations_invoke_callback')) {
	function malibu_migrations_invoke_callback($callback, $item = [])
	{
		try {
			return call_user_func($callback, $item);
		} catch (ArgumentCountError $error) {
			return call_user_func($callback);
		} catch (Throwable $error) {
			return new WP_Error(
				'malibu_migration_callback_error',
				'Migration callback failed: ' . $error->getMessage()
			);
		}
	}
}

if (!function_exists('malibu_migrations_table_exists')) {
	function malibu_migrations_table_exists($table_name)
	{
		global $wpdb;

		$table_name = sanitize_key((string) $table_name);
		if ($table_name === '') {
			return false;
		}

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM information_schema.tables
				WHERE table_schema = DATABASE()
					AND table_name = %s",
				$table_name
			)
		);

		return (int) $found > 0;
	}
}

if (!function_exists('malibu_migrations_column_exists')) {
	function malibu_migrations_column_exists($table_name, $column_name)
	{
		global $wpdb;

		$table_name = sanitize_key((string) $table_name);
		$column_name = sanitize_key((string) $column_name);
		if ($table_name === '' || $column_name === '') {
			return false;
		}

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM information_schema.columns
				WHERE table_schema = DATABASE()
					AND table_name = %s
					AND column_name = %s",
				$table_name,
				$column_name
			)
		);

		return (int) $found > 0;
	}
}

if (!function_exists('malibu_migrations_index_exists')) {
	function malibu_migrations_index_exists($table_name, $index_name)
	{
		global $wpdb;

		$table_name = sanitize_key((string) $table_name);
		$index_name = sanitize_key((string) $index_name);
		if ($table_name === '' || $index_name === '') {
			return false;
		}

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM information_schema.statistics
				WHERE table_schema = DATABASE()
					AND table_name = %s
					AND index_name = %s",
				$table_name,
				$index_name
			)
		);

		return (int) $found > 0;
	}
}

if (!function_exists('malibu_migrations_ensure_history_table')) {
	function malibu_migrations_ensure_history_table()
	{
		static $checked = false;
		if ($checked) {
			return true;
		}

		global $wpdb;

		$table = malibu_migrations_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			item_type varchar(20) NOT NULL,
			item_key varchar(191) NOT NULL,
			title varchar(255) NOT NULL DEFAULT '',
			output_text longtext NULL,
			applied_by bigint(20) unsigned NULL,
			applied_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY item_type_key (item_type, item_key),
			KEY applied_at (applied_at)
		) {$charset_collate};";

		dbDelta($sql);
		$checked = true;

		return true;
	}
}

if (!function_exists('malibu_migrations_get_history_row')) {
	function malibu_migrations_get_history_row($item_type, $item_key)
	{
		malibu_migrations_ensure_history_table();

		$item_type = $item_type === 'patch' ? 'patch' : 'migration';
		$item_key = malibu_migrations_normalize_key($item_key);
		if ($item_key === '') {
			return null;
		}

		global $wpdb;

		$table = malibu_migrations_table_name();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, item_type, item_key, title, output_text, applied_by, applied_at
				FROM {$table}
				WHERE item_type = %s
					AND item_key = %s
				LIMIT 1",
				$item_type,
				$item_key
			),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}
}

if (!function_exists('malibu_migrations_insert_history_row')) {
	function malibu_migrations_insert_history_row($item_type, $item, $summary, $messages = [])
	{
		malibu_migrations_ensure_history_table();

		global $wpdb;

		$table = malibu_migrations_table_name();
		$inserted = $wpdb->insert(
			$table,
			[
				'item_type' => $item_type === 'patch' ? 'patch' : 'migration',
				'item_key' => (string) ($item['key'] ?? ''),
				'title' => (string) ($item['title'] ?? ''),
				'output_text' => malibu_migrations_format_output_text($summary, $messages),
				'applied_by' => get_current_user_id() ? (int) get_current_user_id() : null,
				'applied_at' => current_time('mysql'),
			],
			[
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
			]
		);

		if ($inserted === false) {
			$existing = malibu_migrations_get_history_row($item_type, (string) ($item['key'] ?? ''));
			if ($existing) {
				return $existing;
			}

			return null;
		}

		return malibu_migrations_get_history_row($item_type, (string) ($item['key'] ?? ''));
	}
}

if (!function_exists('malibu_migrations_pending_history_option_name')) {
	function malibu_migrations_pending_history_option_name()
	{
		return 'malibu_migrations_pending_history';
	}
}

if (!function_exists('malibu_migrations_pending_history_storage_key')) {
	function malibu_migrations_pending_history_storage_key($item_type, $item_key)
	{
		$item_type = $item_type === 'patch' ? 'patch' : 'migration';
		$item_key = malibu_migrations_normalize_key($item_key);
		if ($item_key === '') {
			return '';
		}

		return $item_type . ':' . $item_key;
	}
}

if (!function_exists('malibu_migrations_get_pending_history_items')) {
	function malibu_migrations_get_pending_history_items()
	{
		if (!function_exists('get_option')) {
			return [];
		}

		$items = get_option(malibu_migrations_pending_history_option_name(), []);

		return is_array($items) ? $items : [];
	}
}

if (!function_exists('malibu_migrations_get_pending_history_item')) {
	function malibu_migrations_get_pending_history_item($item_type, $item_key)
	{
		$storage_key = malibu_migrations_pending_history_storage_key($item_type, $item_key);
		if ($storage_key === '') {
			return null;
		}

		$items = malibu_migrations_get_pending_history_items();
		$item = $items[$storage_key] ?? null;

		return is_array($item) ? $item : null;
	}
}

if (!function_exists('malibu_migrations_store_pending_history_item')) {
	function malibu_migrations_store_pending_history_item($item_type, $item, $summary, $messages = [])
	{
		if (!function_exists('get_option') || !function_exists('add_option') || !function_exists('update_option')) {
			return false;
		}

		$storage_key = malibu_migrations_pending_history_storage_key($item_type, (string) ($item['key'] ?? ''));
		if ($storage_key === '') {
			return false;
		}

		$items = malibu_migrations_get_pending_history_items();
		$items[$storage_key] = [
			'item_type' => $item_type === 'patch' ? 'patch' : 'migration',
			'item_key' => (string) ($item['key'] ?? ''),
			'title' => (string) ($item['title'] ?? ''),
			'summary' => trim((string) $summary),
			'messages' => malibu_migrations_collect_messages($messages),
			'recorded_at' => current_time('mysql'),
		];

		$option_name = malibu_migrations_pending_history_option_name();
		$existing = get_option($option_name, null);

		if ($existing === null) {
			add_option($option_name, $items, '', false);
		} else {
			update_option($option_name, $items, false);
		}

		$verified = malibu_migrations_get_pending_history_items();

		return isset($verified[$storage_key]) && is_array($verified[$storage_key]);
	}
}

if (!function_exists('malibu_migrations_clear_pending_history_item')) {
	function malibu_migrations_clear_pending_history_item($item_type, $item_key)
	{
		if (!function_exists('update_option') || !function_exists('delete_option')) {
			return false;
		}

		$storage_key = malibu_migrations_pending_history_storage_key($item_type, $item_key);
		if ($storage_key === '') {
			return false;
		}

		$items = malibu_migrations_get_pending_history_items();
		if (!isset($items[$storage_key])) {
			return true;
		}

		unset($items[$storage_key]);

		$option_name = malibu_migrations_pending_history_option_name();
		if ($items === []) {
			delete_option($option_name);
			return malibu_migrations_get_pending_history_item($item_type, $item_key) === null;
		}

		update_option($option_name, $items, false);

		return malibu_migrations_get_pending_history_item($item_type, $item_key) === null;
	}
}

if (!function_exists('malibu_migrations_log_error')) {
	function malibu_migrations_log_error($message)
	{
		$message = trim((string) $message);
		if ($message === '') {
			return;
		}

		error_log('[Malibu Migrations] ' . $message);
	}
}

if (!function_exists('malibu_migrations_registry_dir')) {
	function malibu_migrations_registry_dir($item_type)
	{
		$base_dir = trailingslashit(malibu_migrations_base_dir());

		if ($item_type === 'patch') {
			return (string) apply_filters('malibu_patches_dir', $base_dir . 'seeders');
		}

		return (string) apply_filters('malibu_migrations_dir', $base_dir . 'migrations');
	}
}

if (!function_exists('malibu_migrations_expand_file_definition')) {
	function malibu_migrations_expand_file_definition($loaded, $item_type, $file_path)
	{
		$definitions = [];
		$file_key = malibu_migrations_normalize_key(pathinfo((string) $file_path, PATHINFO_FILENAME));

		if (!is_array($loaded)) {
			return $definitions;
		}

		$looks_like_single = array_key_exists('callback', $loaded)
			|| array_key_exists('title', $loaded)
			|| array_key_exists('key', $loaded);

		if ($looks_like_single) {
			$loaded['key'] = isset($loaded['key']) ? $loaded['key'] : $file_key;
			$definitions[] = $loaded;
			return $definitions;
		}

		foreach ($loaded as $definition) {
			if (!is_array($definition)) {
				continue;
			}

			$definition['key'] = isset($definition['key']) ? $definition['key'] : $file_key;
			$definitions[] = $definition;
		}

		return $definitions;
	}
}

if (!function_exists('malibu_migrations_normalize_registry')) {
	function malibu_migrations_normalize_registry($definitions, $item_type)
	{
		$normalized = [];
		if (!is_array($definitions)) {
			return $normalized;
		}

		foreach ($definitions as $key => $definition) {
			if (!is_array($definition)) {
				continue;
			}

			$resolved_key = malibu_migrations_normalize_key($definition['key'] ?? $key);
			if ($resolved_key === '') {
				continue;
			}

			$definition['key'] = $resolved_key;
			$definition['title'] = trim((string) ($definition['title'] ?? $resolved_key));
			$definition['description'] = trim((string) ($definition['description'] ?? ''));
			$definition['item_type'] = $item_type === 'patch' ? 'patch' : 'migration';

			if (isset($normalized[$resolved_key])) {
				$item_label = $item_type === 'patch' ? 'patch' : 'migration';
				$existing_source = (string) ($normalized[$resolved_key]['file'] ?? $normalized[$resolved_key]['title'] ?? 'unknown');
				$current_source = (string) ($definition['file'] ?? $definition['title'] ?? 'unknown');

				return new WP_Error(
					'malibu_migrations_duplicate_key',
					sprintf(
						'Duplicate %s key "%s" detected in "%s" and "%s".',
						$item_label,
						$resolved_key,
						$existing_source,
						$current_source
					)
				);
			}

			$normalized[$resolved_key] = $definition;
		}

		ksort($normalized, SORT_NATURAL);

		return $normalized;
	}
}

if (!function_exists('malibu_migrations_load_registry')) {
	function malibu_migrations_load_registry($item_type)
	{
		$item_type = $item_type === 'patch' ? 'patch' : 'migration';
		$dir = malibu_migrations_registry_dir($item_type);
		$files = glob(trailingslashit($dir) . '*.php');

		if (!is_array($files)) {
			$files = [];
		}

		sort($files, SORT_NATURAL);

		$definitions = [];
		foreach ($files as $file_path) {
			$loaded = require $file_path;
			foreach (malibu_migrations_expand_file_definition($loaded, $item_type, $file_path) as $definition) {
				$definition['file'] = $file_path;
				$definitions[] = $definition;
			}
		}

		$hook = $item_type === 'patch'
			? 'malibu_patches_registry'
			: 'malibu_migrations_registry';

		return malibu_migrations_normalize_registry(
			apply_filters($hook, $definitions),
			$item_type
		);
	}
}

if (!function_exists('malibu_migrations_run_item')) {
	function malibu_migrations_run_item($item_type, $item)
	{
		$response = [
			'ok' => false,
			'already_applied' => false,
			'item' => $item,
			'history' => null,
			'messages' => [],
			'summary' => '',
		];

		$item_type = $item_type === 'patch' ? 'patch' : 'migration';
		$item_key = (string) ($item['key'] ?? '');
		if ($item_key === '') {
			$response['messages'][] = 'Item key is missing.';
			return $response;
		}

		$existing = malibu_migrations_get_history_row($item_type, $item_key);
		if ($existing) {
			malibu_migrations_clear_pending_history_item($item_type, $item_key);
			$response['ok'] = true;
			$response['already_applied'] = true;
			$response['history'] = $existing;
			$response['summary'] = 'Item already applied.';
			$response['messages'][] = 'First execution was recorded at ' . (string) ($existing['applied_at'] ?? '') . '.';
			return $response;
		}

		$pending_history = malibu_migrations_get_pending_history_item($item_type, $item_key);
		if ($pending_history) {
			$response['summary'] = 'Automatic re-run blocked.';
			$response['messages'][] = 'Previous callback may have completed, but the history row was not saved.';

			if (!empty($pending_history['recorded_at'])) {
				$response['messages'][] = 'Pending safeguard recorded at ' . (string) $pending_history['recorded_at'] . '.';
			}

			if (!empty($pending_history['summary'])) {
				$response['messages'][] = 'Last callback summary: ' . (string) $pending_history['summary'];
			}

			$response['messages'][] = 'Fix history storage or resolve the pending safeguard before re-running this item.';
			malibu_migrations_log_error(
				sprintf(
					'Automatic re-run blocked for %s "%s" because a pending history safeguard exists.',
					$item_type,
					$item_key
				)
			);
			return $response;
		}

		$callback = $item['callback'] ?? null;
		if (!is_callable($callback)) {
			$response['messages'][] = 'Item callback is not callable.';
			return $response;
		}

		$callback_result = malibu_migrations_invoke_callback($callback, $item);
		if (is_wp_error($callback_result)) {
			$error_data = $callback_result->get_error_data();
			if (is_array($error_data) && isset($error_data['messages'])) {
				$response['messages'] = array_merge(
					$response['messages'],
					malibu_migrations_collect_messages($error_data['messages'])
				);
			}

			$response['messages'][] = (string) $callback_result->get_error_message();
			return $response;
		}

		$summary = '';
		$messages = [];
		if (is_array($callback_result)) {
			$summary = trim((string) ($callback_result['summary'] ?? ''));
			$messages = malibu_migrations_collect_messages($callback_result['messages'] ?? []);
		}

		if ($summary === '') {
			$summary = $item_type === 'patch'
				? 'Patch applied.'
				: 'Migration applied.';
		}

		$history = malibu_migrations_insert_history_row($item_type, $item, $summary, $messages);
		if (!$history) {
			global $wpdb;

			$safeguard_saved = malibu_migrations_store_pending_history_item($item_type, $item, $summary, $messages);
			$response['summary'] = 'Item callback finished, but the history row could not be saved.';
			$response['messages'] = array_merge(
				$messages,
				['Automatic re-run has been blocked for safety.']
			);

			$log_message = sprintf(
				'History write failed after successful %s "%s".',
				$item_type,
				$item_key
			);

			if (!empty($wpdb->last_error)) {
				$log_message .= ' DB error: ' . (string) $wpdb->last_error;
			}

			if (!$safeguard_saved) {
				$response['messages'][] = 'Safety marker could not be stored; this item may run again on the next request.';
				$log_message .= ' Pending safeguard could not be stored.';
			}

			malibu_migrations_log_error($log_message);

			return $response;
		}

		malibu_migrations_clear_pending_history_item($item_type, $item_key);
		$response['ok'] = true;
		$response['history'] = $history;
		$response['summary'] = $summary;
		$response['messages'] = $messages;

		return $response;
	}
}

if (!function_exists('malibu_migrations_run_queue')) {
	function malibu_migrations_run_queue($item_type)
	{
		$queue = malibu_migrations_load_registry($item_type);
		if (is_wp_error($queue)) {
			malibu_migrations_log_error((string) $queue->get_error_message());

			return [[
				'ok' => false,
				'already_applied' => false,
				'item' => [
					'item_type' => $item_type === 'patch' ? 'patch' : 'migration',
				],
				'history' => null,
				'messages' => [(string) $queue->get_error_message()],
				'summary' => 'Registry load failed.',
			]];
		}

		$results = [];

		foreach ($queue as $item) {
			$result = malibu_migrations_run_item($item_type, $item);
			$results[] = $result;

			if (empty($result['ok'])) {
				break;
			}
		}

		return $results;
	}
}

if (!function_exists('malibu_migrations_acquire_lock')) {
	function malibu_migrations_acquire_lock()
	{
		$key = malibu_migrations_lock_key();
		$ttl = (int) apply_filters('malibu_migrations_lock_ttl', 60);
		$ttl = $ttl > 0 ? $ttl : 60;

		if (!function_exists('get_transient') || !function_exists('set_transient')) {
			return true;
		}

		if (get_transient($key)) {
			return false;
		}

		set_transient($key, 1, $ttl);

		return true;
	}
}

if (!function_exists('malibu_migrations_release_lock')) {
	function malibu_migrations_release_lock()
	{
		if (!function_exists('delete_transient')) {
			return;
		}

		delete_transient(malibu_migrations_lock_key());
	}
}

if (!function_exists('malibu_migrations_run_all')) {
	function malibu_migrations_run_all()
	{
		$results = [
			'migrations' => [],
			'patches' => [],
			'lock_skipped' => false,
		];

		if (!malibu_migrations_acquire_lock()) {
			$results['lock_skipped'] = true;
			return $results;
		}

		try {
			malibu_migrations_ensure_history_table();
			$results['migrations'] = malibu_migrations_run_queue('migration');

			$can_run_patches = true;
			foreach ($results['migrations'] as $result) {
				if (empty($result['ok'])) {
					$can_run_patches = false;
					break;
				}
			}

			if ($can_run_patches) {
				$results['patches'] = malibu_migrations_run_queue('patch');
			}
		} finally {
			malibu_migrations_release_lock();
		}

		return $results;
	}
}

if (!function_exists('malibu_migrations_maybe_run')) {
	function malibu_migrations_maybe_run()
	{
		static $has_run = false;
		if ($has_run) {
			return;
		}

		$has_run = true;

		if (!malibu_migrations_autorun_enabled()) {
			return;
		}

		malibu_migrations_run_all();
	}
}

if (!function_exists('malibu_migrations_boot')) {
	function malibu_migrations_boot()
	{
		static $booted = false;
		if ($booted) {
			return;
		}

		$booted = true;
		add_action('init', 'malibu_migrations_maybe_run', 20);
	}
}

malibu_migrations_boot();

<?php

if (!defined('ABSPATH')) {
	exit;
}

if (!function_exists('malibu_error_log_normalize_version')) {
	function malibu_error_log_normalize_version($version)
	{
		$version = strtolower(trim((string) $version));

		if (in_array($version, ['1', 'v1', 'version1', 'viewer1'], true)) {
			return 'v1';
		}

		if (in_array($version, ['2', 'v2', 'version2', 'viewer2'], true)) {
			return 'v2';
		}

		return 'v2';
	}
}

if (!function_exists('malibu_error_log_get_settings')) {
	function malibu_error_log_get_settings()
	{
		$version = defined('MALIBU_ERROR_LOG_VERSION') ? MALIBU_ERROR_LOG_VERSION : 'v2';
		$version = apply_filters('malibu_error_log_version', $version);

		return [
			'page_title' => (string) apply_filters('malibu_error_log_page_title', 'Error Log'),
			'menu_title' => (string) apply_filters('malibu_error_log_menu_title', 'Error Log'),
			'page_slug' => (string) apply_filters('malibu_error_log_page_slug', 'malibu-error-log'),
			'capability' => (string) apply_filters('malibu_error_log_capability', 'manage_options'),
			'menu_icon' => (string) apply_filters('malibu_error_log_menu_icon', 'dashicons-visibility'),
			'menu_position' => (int) apply_filters('malibu_error_log_menu_position', 100),
			'file_path' => (string) apply_filters('malibu_error_log_file_path', WP_CONTENT_DIR . '/debug.log'),
			'lines_per_page' => max(1, (int) apply_filters('malibu_error_log_lines_per_page', 100)),
			'default_badges' => (bool) apply_filters('malibu_error_log_default_badges', true),
			'version' => malibu_error_log_normalize_version($version),
		];
	}
}

if (!function_exists('malibu_error_log_get_admin_url')) {
	function malibu_error_log_get_admin_url(array $args = [])
	{
		$settings = malibu_error_log_get_settings();

		return add_query_arg(
			array_merge(
				[
					'page' => $settings['page_slug'],
				],
				$args
			),
			admin_url('admin.php')
		);
	}
}

if (!function_exists('malibu_error_log_get_viewer_definition')) {
	function malibu_error_log_get_viewer_definition($version = null)
	{
		$settings = malibu_error_log_get_settings();
		$version = malibu_error_log_normalize_version($version ?: $settings['version']);

		$map = [
			'v1' => __DIR__ . '/error-log/viewer-v1.php',
			'v2' => __DIR__ . '/error-log/viewer-v2.php',
		];

		$file = isset($map[$version]) ? $map[$version] : $map['v2'];
		$definition = require $file;

		if (!is_array($definition)) {
			$definition = [];
		}

		$definition['id'] = malibu_error_log_normalize_version($definition['id'] ?? $version);
		$definition['label'] = (string) ($definition['label'] ?? strtoupper($definition['id']));
		$definition['description'] = (string) ($definition['description'] ?? '');
		$definition['supports_badges'] = !empty($definition['supports_badges']);
		$definition['format_line'] = isset($definition['format_line']) && is_callable($definition['format_line'])
			? $definition['format_line']
			: static function ($line) {
				return (string) $line;
			};

		return $definition;
	}
}

if (!function_exists('malibu_error_log_register_admin_page')) {
	function malibu_error_log_register_admin_page()
	{
		$settings = malibu_error_log_get_settings();

		add_menu_page(
			$settings['page_title'],
			$settings['menu_title'],
			$settings['capability'],
			$settings['page_slug'],
			'malibu_error_log_render_admin_page',
			$settings['menu_icon'],
			$settings['menu_position']
		);
	}

	add_action('admin_menu', 'malibu_error_log_register_admin_page');
}

if (!function_exists('malibu_error_log_clear_file')) {
	function malibu_error_log_clear_file($file_path)
	{
		if (!file_exists($file_path)) {
			return 'Log file does not exist.';
		}

		$result = @file_put_contents($file_path, '');

		if ($result === false) {
			return 'Failed to clear log file.';
		}

		return 'Log file was cleared.';
	}
}

if (!function_exists('malibu_error_log_get_notice_message')) {
	function malibu_error_log_get_notice_message($notice_code)
	{
		$notice_code = sanitize_key((string) $notice_code);

		if ($notice_code === 'cleared') {
			return 'Log file was cleared.';
		}

		if ($notice_code === 'clear_failed') {
			return 'Failed to clear log file.';
		}

		if ($notice_code === 'missing_file') {
			return 'Log file does not exist.';
		}

		return '';
	}
}

if (!function_exists('malibu_error_log_read_content')) {
	function malibu_error_log_read_content($file_path, $lines_per_page, $current_page, $search_term, $apply_badges, array $viewer)
	{
		if (!file_exists($file_path)) {
			return [
				'content' => 'Log file does not exist.',
				'total_pages' => 1,
				'total_lines' => 0,
			];
		}

		$log_lines = @file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

		if (!is_array($log_lines)) {
			return [
				'content' => 'Unable to read log file.',
				'total_pages' => 1,
				'total_lines' => 0,
			];
		}

		if ($search_term !== '') {
			$log_lines = array_values(array_filter($log_lines, static function ($line) use ($search_term) {
				return stripos((string) $line, $search_term) !== false;
			}));
		}

		$total_lines = count($log_lines);

		if ($total_lines === 0) {
			return [
				'content' => 'No log entries for current filters.',
				'total_pages' => 1,
				'total_lines' => 0,
			];
		}

		$total_pages = (int) ceil($total_lines / max(1, $lines_per_page));
		$current_page = min(max(1, (int) $current_page), $total_pages);
		$start_line = ($current_page - 1) * $lines_per_page;
		$page_lines = array_slice($log_lines, $start_line, $lines_per_page);

		if ($apply_badges && !empty($viewer['supports_badges']) && is_callable($viewer['format_line'])) {
			foreach ($page_lines as &$line) {
				$line = (string) call_user_func($viewer['format_line'], $line);
			}
			unset($line);
		}

		return [
			'content' => implode("\n", $page_lines),
			'total_pages' => $total_pages,
			'total_lines' => $total_lines,
		];
	}
}

if (!function_exists('malibu_error_log_render_admin_page')) {
	function malibu_error_log_render_admin_page()
	{
		$settings = malibu_error_log_get_settings();

		if (!current_user_can($settings['capability'])) {
			wp_die(esc_html__('You do not have permission to access this page.', 'doverka-backoffice'));
		}

		if (isset($_POST['malibu_error_log_clear'])) {
			check_admin_referer('malibu_error_log_clear');

			$result_message = malibu_error_log_clear_file($settings['file_path']);
			$notice_code = 'clear_failed';

			if ($result_message === 'Log file was cleared.') {
				$notice_code = 'cleared';
			} elseif ($result_message === 'Log file does not exist.') {
				$notice_code = 'missing_file';
			}

			$redirect_args = [
				'malibu_error_log_notice' => $notice_code,
				'paged' => 1,
			];

			if (!empty($_POST['search_term'])) {
				$redirect_args['search_term'] = sanitize_text_field(wp_unslash($_POST['search_term']));
			}

			if (isset($_POST['use_badges'])) {
				$redirect_args['use_badges'] = (int) (bool) wp_unslash($_POST['use_badges']);
			}

			wp_safe_redirect(malibu_error_log_get_admin_url($redirect_args));
			exit;
		}

		$viewer = malibu_error_log_get_viewer_definition($settings['version']);
		$current_page = isset($_GET['paged'])
			? max(1, absint($_GET['paged']))
			: (isset($_POST['current_paged']) ? max(1, absint($_POST['current_paged'])) : 1);

		$search_term = '';
		if (isset($_POST['search_term'])) {
			$search_term = sanitize_text_field(wp_unslash($_POST['search_term']));
		} elseif (isset($_GET['search_term'])) {
			$search_term = sanitize_text_field(wp_unslash($_GET['search_term']));
		}

		$apply_badges = $settings['default_badges'];
		if ($viewer['supports_badges']) {
			$apply_badges = isset($_REQUEST['use_badges'])
				? (bool) absint($_REQUEST['use_badges'])
				: $settings['default_badges'];
		} else {
			$apply_badges = false;
		}

		$log_content = malibu_error_log_read_content(
			$settings['file_path'],
			$settings['lines_per_page'],
			$current_page,
			$search_term,
			$apply_badges,
			$viewer
		);

		$refresh_args = [
			'paged' => $current_page,
		];
		if ($search_term !== '') {
			$refresh_args['search_term'] = $search_term;
		}
		if ($viewer['supports_badges']) {
			$refresh_args['use_badges'] = (int) $apply_badges;
		}
		$refresh_url = malibu_error_log_get_admin_url($refresh_args);
		$notice_message = malibu_error_log_get_notice_message($_GET['malibu_error_log_notice'] ?? '');
		?>
		<div class="wrap">
			<h1><?php echo esc_html($settings['page_title']); ?></h1>

			<p>
				<?php echo esc_html(sprintf('Viewer: %s', $viewer['label'])); ?><br>
				<?php echo esc_html(sprintf('File: %s', $settings['file_path'])); ?>
			</p>

			<?php if ($viewer['description'] !== '') : ?>
				<p><?php echo esc_html($viewer['description']); ?></p>
			<?php endif; ?>

			<?php if ($notice_message !== '') : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html($notice_message); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field('malibu_error_log_clear'); ?>
				<p style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
					<button type="submit" name="malibu_error_log_clear" class="button button-primary" value="1">Clear log</button>
					<a class="button" href="<?php echo esc_url($refresh_url); ?>">Refresh</a>
					<input type="hidden" name="current_paged" value="<?php echo (int) $current_page; ?>">
					<input type="text" name="search_term" value="<?php echo esc_attr($search_term); ?>" placeholder="Search...">
					<?php if ($viewer['supports_badges']) : ?>
						<input type="hidden" name="use_badges" value="0">
						<label style="user-select:none; display:inline-flex; gap:6px; align-items:center;">
							<input
								type="checkbox"
								name="use_badges"
								value="1"
								<?php checked($apply_badges, true); ?>
								onchange="this.form.submit()"
							>
							Show severity and source badges
						</label>
					<?php endif; ?>
					<button type="submit" class="button" name="malibu_error_log_search" value="1">Search</button>
				</p>
			</form>

			<h2>Log content</h2>
			<p><?php echo esc_html(sprintf('Matched lines: %d', (int) $log_content['total_lines'])); ?></p>
			<textarea readonly rows="45" style="width: 100%; font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;"><?php echo esc_textarea($log_content['content']); ?></textarea>

			<div class="tablenav">
				<div class="tablenav-pages">
					<?php
					$total_pages = (int) $log_content['total_pages'];
					if ($total_pages > 1) {
						$query_args = [];
						if ($search_term !== '') {
							$query_args['search_term'] = $search_term;
						}
						if ($viewer['supports_badges']) {
							$query_args['use_badges'] = (int) $apply_badges;
						}

						$base_url = malibu_error_log_get_admin_url($query_args);

						echo wp_kses_post(
							paginate_links([
								'base' => $base_url . '%_%',
								'format' => '&paged=%#%',
								'current' => $current_page,
								'total' => $total_pages,
								'prev_text' => __('&laquo; Previous'),
								'next_text' => __('Next &raquo;'),
								'type' => 'plain',
							])
						);
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}
}

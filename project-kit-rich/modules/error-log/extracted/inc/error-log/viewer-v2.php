<?php

if (!defined('ABSPATH')) {
	exit;
}

$detect_severity = static function ($line) {
	$map = [
		'/PHP Fatal error|Uncaught (?:Error|Exception)|Allowed memory size|Segmentation fault/i' => ['fatal', '🛑🛑🛑'],
		'/WordPress database error|MySQL server has gone away|Deadlock found|Lock wait timeout/i' => ['error', '❗❗❗'],
		'/PHP Parse error|E_PARSE/i' => ['error', '❗❗❗'],
		'/PHP Warning|E_WARNING|Deprecated function argument|headers already sent/i' => ['warn', '⚠️⚠️⚠️'],
		'/PHP Notice|E_NOTICE|Undefined (?:index|variable|array key)/i' => ['notice', '⚠️'],
		'/Doing it wrong|_doing_it_wrong|Translation loading .* too early|Function .* was called incorrectly/i' => ['notice', '⚠️'],
		'/PHP message:|info/i' => ['info', 'ℹ️'],
	];

	foreach ($map as $pattern => $result) {
		if (preg_match($pattern, $line)) {
			return [
				'level' => $result[0],
				'emoji' => $result[1],
			];
		}
	}

	return [
		'level' => 'other',
		'emoji' => '•',
	];
};

$detect_sources = static function ($line) {
	$tags = [];

	if (preg_match('/WordPress database error|wpdb|mysqli|PDO|SQLSTATE|MySQL/i', $line)) {
		$tags[] = ['DB', '🧰'];
	}
	if (preg_match('/PHP (?:Fatal error|Warning|Notice|Deprecated|Parse error)|Uncaught/i', $line)) {
		$tags[] = ['PHP', '🐘'];
	}
	if (preg_match('/WordPress|Doing it wrong|_doing_it_wrong|WP_|wp-includes|wp-content/i', $line)) {
		$tags[] = ['WP', '🧩'];
	}
	if (preg_match('/CRON|WP\-Cron|doing_cron/i', $line)) {
		$tags[] = ['CRON', '⏰'];
	}
	if (preg_match('/REST API|wp-json|rest_(?:do_request|validate)/i', $line)) {
		$tags[] = ['REST', '🔗'];
	}
	if (preg_match('/i18n|_load_textdomain_just_in_time|Translation/i', $line)) {
		$tags[] = ['I18N', '🌐'];
	}
	if (preg_match('/filesystem|permissions|open stream|No such file or directory|Permission denied/i', $line)) {
		$tags[] = ['FS', '📁'];
	}
	if (preg_match('/Allowed memory size|Out of memory/i', $line)) {
		$tags[] = ['MEM', '💾'];
	}
	if (preg_match('/headers already sent/i', $line)) {
		$tags[] = ['HDR', '✉️'];
	}

	if (!$tags) {
		$tags[] = ['MISC', '🔎'];
	}

	return $tags;
};

$detect_db_kind = static function ($line) {
	$map = [
		'/(MySQL server has gone away|Lost connection|Can\'t connect|Connection refused|Packets out of order|server has gone away)/i' => 'CONN',
		'/Deadlock found/i' => 'DEADLOCK',
		'/Lock wait timeout/i' => 'TIMEOUT',
		'/Unknown column|Unknown table|doesn\'t exist|Unknown database/i' => 'SCHEMA',
		'/Duplicate entry/i' => 'DUP',
		'/You have an error in your SQL syntax|SQL syntax/i' => 'SYNTAX',
		'/foreign key constraint fails|Cannot add or update a child row/i' => 'FK',
		'/Access denied for user/i' => 'ACCESS',
		'/Data too long for column|Truncated incorrect/i' => 'TRUNC',
		'/table is full|No space left on device/i' => 'SPACE',
		'/read[-\s]?only/i' => 'READONLY',
	];

	foreach ($map as $pattern => $code) {
		if (preg_match($pattern, $line)) {
			return $code;
		}
	}

	if (preg_match('/SQLSTATE\[(\w+)\]/i', $line, $matches)) {
		return 'STATE:' . strtoupper(substr($matches[1], 0, 2));
	}

	return '';
};

$build_source_tags = static function ($line, array $sources) use ($detect_db_kind) {
	$parts = [];

	foreach ($sources as $source) {
		$label = (string) ($source[0] ?? '');
		$emoji = (string) ($source[1] ?? '');

		if ($label === 'DB') {
			$kind = $detect_db_kind($line);
			if ($kind !== '') {
				$parts[] = $emoji . $label . '·' . $kind;
				continue;
			}
		}

		$parts[] = $emoji . $label;
	}

	return $parts;
};

$format_line = static function ($line) use ($detect_severity, $detect_sources, $build_source_tags) {
	$severity = $detect_severity($line);
	$sources = $detect_sources($line);
	$prefix_parts = [];

	if (!empty($severity['emoji'])) {
		$prefix_parts[] = $severity['emoji'];
	}

	if ($sources) {
		$prefix_parts = array_merge($prefix_parts, $build_source_tags($line, $sources));
	}

	$prefix = $prefix_parts ? implode(' ', $prefix_parts) . ' ' : '';

	return $prefix . (string) $line;
};

return [
	'id' => 'v2',
	'label' => 'V2',
	'description' => 'Enhanced viewer extracted from Doverka default debug-log module with severity and source badges.',
	'supports_badges' => true,
	'format_line' => $format_line,
];

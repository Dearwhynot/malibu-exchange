<?php

if (!defined('ABSPATH')) {
	exit;
}

return [
	'id' => 'v1',
	'label' => 'V1',
	'description' => 'Plain raw log viewer without severity or source badges.',
	'supports_badges' => false,
	'format_line' => static function ($line) {
		return (string) $line;
	},
];

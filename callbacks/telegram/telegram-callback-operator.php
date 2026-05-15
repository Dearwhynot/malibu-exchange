<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['CRM_TELEGRAM_CALLBACK_CONTEXT'] = 'operator';

if ( ! defined( 'TG_BOT_CONTEXT' ) ) {
	define( 'TG_BOT_CONTEXT', 'operator' );
}

require_once __DIR__ . '/telegram-callback-universal.php';

<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['CRM_TELEGRAM_CALLBACK_CONTEXT'] = 'merchant';

if ( ! defined( 'TG_BOT_CONTEXT' ) ) {
	define( 'TG_BOT_CONTEXT', 'merchant' );
}

require_once __DIR__ . '/telegram-callback-universal.php';

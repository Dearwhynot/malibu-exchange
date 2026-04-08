<?php
/*
 * Telegram Universal Callback (single-file starter)
 *
 * Consolidated from legacy callbacks:
 * - Telegram.php
 * - callback-tg-fifo-payouts.php
 * - callback-tg-custom-payments.php
 * - callback-tg-megatix-v3-bali.php
 * - callback-tg-megatix.php
 * - callback-tg-register-new-user.php
 * - callback-tg-service-bot.php
 * - callback-tg-webapp-calc-referal.php
 * - callback-tg-webapp-tickets-all.php
 * - callback-tg-aoe.php
 * - callback-tg-aoe-v2.php
 * - test_callback-tg-aoe.php
 *
 * Practical notes:
 * - Keep this file as the main callback entrypoint.
 * - Keep project-specific logic in adapter functions below.
 * - Kanyon and Doverka adapters fail safely if integration functions are absent.
 */

//? @PhuketCashExchangeBot
//? 8245726806:AAGtA4dYraDcCngEKcd8_cvQnuB9DGfQQes
//? https://malibu.exchange/wp-json/malibu-exchange/v1/telegram/callback-universal
//? https://api.telegram.org/bot8245726806:AAGtA4dYraDcCngEKcd8_cvQnuB9DGfQQes/setWebhook?url=https://malibu.exchange/wp-json/malibu-exchange/v1/telegram/callback-universal

if (!defined('TG_UNIVERSAL_CALLBACK_VERSION')) {
    define('TG_UNIVERSAL_CALLBACK_VERSION', '1.0.0');
}

DEFINE('TG_UNIVERSAL_DEBUG', false);
DEFINE('TG_UNDER_CONSTRUCTION_MODE', false);
DEFINE('TG_UNDER_CONSTRUCTION_TEXT', 'Service is temporarily under maintenance.');
DEFINE('TG_UNIVERSAL_TIMEZONE', 'Asia/Bangkok');
DEFINE('TG_BOT_TOKEN', '8245726806:AAGtA4dYraDcCngEKcd8_cvQnuB9DGfQQes');
DEFINE('KANYON_CURRENCIES_URL', '8245726806:AAGtA4dYraDcCngEKcd8_cvQnuB9DGfQQes');
DEFINE('TG_DEBUG_ADMIN_CHAT_ID', '160457790');
DEFINE('KANYON_ORDER_URL', 'https://kanyonpay.pay2day.kz/api/v1/public/order');
DEFINE('KANYON_TSP_CODE', 'dearwhynot');
   DEFINE('KANYON_QRC_URL_PREFIX', 'https://kanyonpay.pay2day.kz/api/v1/public/order/qrcData/dearwhynot/');

DEFINE('TG_ALLOWED_USERS', ['160457790']); // Example: ['123456789', '987654321']
DEFINE('TG_ALLOWED_CHATS', ['160457790']); // Example: ['-1001234567890', '-1009876543210']
DEFINE('TG_CHAT_ACL', []); // Example: ['-1001234567890' => ['*'], '-1009876543210' => ['123456789']]


$TG_DEBUG = defined('TG_UNIVERSAL_DEBUG') ? (bool) TG_UNIVERSAL_DEBUG : false;
$GLOBALS['TG_UNIVERSAL_DEBUG'] = $TG_DEBUG;

$TG_UNDER_CONSTRUCTION_MODE = defined('TG_UNDER_CONSTRUCTION_MODE') ? (bool) TG_UNDER_CONSTRUCTION_MODE : false;
$TG_UNDER_CONSTRUCTION_TEXT = defined('TG_UNDER_CONSTRUCTION_TEXT')
    ? (string) TG_UNDER_CONSTRUCTION_TEXT
    : 'Service is temporarily under maintenance.';

$TG_ALLOWED_USERS = ['160457790'];
$TG_ALLOWED_CHATS = ['160457790'];
$TG_CHAT_ACL = [];
$TG_START_MENU_ALLOWED_USERS = $TG_ALLOWED_USERS;

$TG_TIMEZONE = defined('TG_UNIVERSAL_TIMEZONE') ? (string) TG_UNIVERSAL_TIMEZONE : 'Asia/Bangkok';
@date_default_timezone_set($TG_TIMEZONE);

/* =========================================================
 * Section: Bootstrap / Runtime Safety
 * ========================================================= */

if (!function_exists('tg_require_telegram_class')) {
    function tg_require_telegram_class()
    {
        if (class_exists('Telegram')) {
            return true;
        }

        $paths = [__DIR__ . '/Telegram.php'];
        for ($i = 1; $i <= 8; $i++) {
            $paths[] = dirname(__DIR__, $i) . '/Telegram.php';
        }

        if (function_exists('get_template_directory')) {
            $paths[] = rtrim(get_template_directory(), '/') . '/Telegram.php';
        }

        foreach (array_unique($paths) as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                require_once $path;
                if (class_exists('Telegram')) {
                    return true;
                }
            }
        }

        return class_exists('Telegram');
    }
}

if (!function_exists('tg_require_qrcode_lib')) {
    function tg_require_qrcode_lib()
    {
        if (class_exists('QRcode')) {
            return true;
        }

        $paths = [
            __DIR__ . '/vendorsphp/phpqrcode/qrlib.php',
            dirname(__DIR__, 4) . '/vendorsphp/phpqrcode/qrlib.php',
        ];

        if (function_exists('get_template_directory')) {
            $paths[] = rtrim(get_template_directory(), '/') . '/vendorsphp/phpqrcode/qrlib.php';
        }

        foreach (array_unique($paths) as $path) {
            if (is_file($path)) {
                require_once $path;
                if (class_exists('QRcode')) {
                    return true;
                }
            }
        }

        return class_exists('QRcode');
    }
}

if (!function_exists('tg_resolve_bot_token')) {
    function tg_resolve_bot_token()
    {
        $candidates = [];

        if (defined('TG_BOT_TOKEN')) {
            $candidates[] = TG_BOT_TOKEN;
        }
        $env = getenv('TG_BOT_TOKEN');
        if ($env !== false) {
            $candidates[] = $env;
        }

        if (isset($_GET['bot_token'])) {
            $candidates[] = $_GET['bot_token'];
        }
        if (isset($_POST['bot_token'])) {
            $candidates[] = $_POST['bot_token'];
        }

        if (function_exists('get_option')) {
            $option_keys = [
                'telegram_bot_token',
                'bot_token',
                'bot_token_service',
                'bot_token_exchange',
            ];
            foreach ($option_keys as $key) {
                $v = get_option($key);
                if (!empty($v) && is_string($v)) {
                    $candidates[] = $v;
                }
            }
        }

        foreach ($candidates as $token) {
            $token = trim((string) $token);
            if ($token !== '') {
                return $token;
            }
        }

        return '';
    }
}

if (!function_exists('tg_get_raw_update')) {
    function tg_get_raw_update()
    {
        if (isset($GLOBALS['TG_UNIVERSAL_RAW_UPDATE']) && is_string($GLOBALS['TG_UNIVERSAL_RAW_UPDATE'])) {
            return $GLOBALS['TG_UNIVERSAL_RAW_UPDATE'];
        }

        $raw = @file_get_contents('php://input');
        return is_string($raw) ? $raw : '';
    }
}

if (!function_exists('tg_decode_update')) {
    function tg_decode_update($raw)
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}

/* =========================================================
 * Section: Logging / Safe Telegram wrappers
 * ========================================================= */

if (!function_exists('fifo_bot_dbg')) {
    function fifo_bot_dbg($message, $context = [])
    {
        $enabled = !empty($GLOBALS['TG_UNIVERSAL_DEBUG']);
        if (!$enabled) {
            return;
        }

        $line = '[tg-universal][debug] ' . (string) $message;
        if (!empty($context)) {
            if (function_exists('wp_json_encode')) {
                $json = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $line .= ' | ' . $json;
        }
        error_log($line);
    }
}

if (!function_exists('bot_send_message')) {
    function bot_send_message($telegram, $chat_id, $text, $keyboard = null)
    {
        if (!$telegram || empty($chat_id)) {
            return false;
        }

        $payload = [
            'chat_id' => $chat_id,
            'text' => (string) $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($keyboard !== null) {
            $payload['reply_markup'] = is_string($keyboard) ? $keyboard : json_encode($keyboard);
        }

        $response = $telegram->sendMessage($payload);
        $ok = is_array($response) && isset($response['ok']) ? (bool) $response['ok'] : false;

        if (!$ok) {
            fifo_bot_dbg('sendMessage failed', ['chat_id' => (string) $chat_id, 'response' => $response]);
        }

        return $response;
    }
}

if (!function_exists('fifo_bot_menu')) {
    function fifo_bot_menu($telegram, $chat_id, $actor_id = null)
    {
        if (!tg_universal_is_user_authorized_for_start_menu($actor_id, $chat_id)) {
            return bot_send_message($telegram, $chat_id, '⛔ Доступ к меню ордеров разрешен только авторизованным пользователям.');
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Обновить курс', 'callback_data' => 'orders_refresh_rate'],
                    ['text' => '🆕 Новый ордер', 'callback_data' => 'orders_new'],
                ],
                [
                    ['text' => '📂 Список открытых ордеров', 'callback_data' => 'orders_open'],
                ],
                [
                    ['text' => '✅ Список закрытых ордеров', 'callback_data' => 'orders_closed'],
                ],
                [
                    ['text' => '❌ Список отмененных ордеров', 'callback_data' => 'orders_canceled'],
                ],
            ],
        ];

        return bot_send_message($telegram, $chat_id, '📌 Выберите действие:', $keyboard);
    }
}

if (!function_exists('tg_universal_is_user_authorized_for_start_menu')) {
    function tg_universal_is_user_authorized_for_start_menu($actor_id, $chat_id = null)
    {
        global $TG_START_MENU_ALLOWED_USERS;
        global $TG_ALLOWED_CHATS;
        global $TG_CHAT_ACL;

        $actor_id = ($actor_id === null || $actor_id === '') ? null : strval($actor_id);
        $chat_id = ($chat_id === null || $chat_id === '') ? null : strval($chat_id);

        if (defined('TG_DEBUG_ADMIN_CHAT_ID')) {
            $debug_admin_id = trim((string) TG_DEBUG_ADMIN_CHAT_ID);
            if (
                ($actor_id !== null && $actor_id === $debug_admin_id)
                || ($chat_id !== null && $chat_id === $debug_admin_id)
            ) {
                return true;
            }
        }

        $allowed = is_array($TG_START_MENU_ALLOWED_USERS) ? $TG_START_MENU_ALLOWED_USERS : [];
        $allowed = array_map('strval', $allowed);
        $allowed = array_map('trim', $allowed);
        if ($actor_id !== null && in_array($actor_id, $allowed, true)) {
            return true;
        }

        $allowed_chats = is_array($TG_ALLOWED_CHATS) ? $TG_ALLOWED_CHATS : [];
        $allowed_chats = array_map('strval', $allowed_chats);
        $allowed_chats = array_map('trim', $allowed_chats);
        if ($chat_id !== null && in_array($chat_id, $allowed_chats, true)) {
            return true;
        }

        if ($chat_id !== null && is_array($TG_CHAT_ACL) && isset($TG_CHAT_ACL[$chat_id])) {
            $rule = $TG_CHAT_ACL[$chat_id];
            if (is_array($rule)) {
                $rule = array_map('strval', $rule);
                if (in_array('*', $rule, true)) {
                    return true;
                }
                if ($actor_id !== null && in_array($actor_id, $rule, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('tg_is_user_authorized_for_start_menu')) {
    function tg_is_user_authorized_for_start_menu($actor_id, $chat_id = null)
    {
        return tg_universal_is_user_authorized_for_start_menu($actor_id, $chat_id);
    }
}

if (!function_exists('tg_safe_answer_callback')) {
    function tg_safe_answer_callback($telegram, $callback_query_id, $text = '', $show_alert = false)
    {
        if (!$telegram || empty($callback_query_id)) {
            return false;
        }

        return $telegram->answerCallbackQuery([
            'callback_query_id' => $callback_query_id,
            'text' => (string) $text,
            'show_alert' => (bool) $show_alert,
        ]);
    }
}

if (!function_exists('tg_send_message_chunks')) {
    function tg_send_message_chunks($telegram, $chat_id, $text, $parse_mode = 'HTML', $reply_markup = null)
    {
        if (!$telegram || empty($chat_id)) {
            return;
        }

        $limit = 4096;
        $text = (string) $text;
        $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);

        if ($len <= $limit) {
            $payload = [
                'chat_id' => $chat_id,
                'text' => $text,
                'parse_mode' => $parse_mode,
                'disable_web_page_preview' => true,
            ];
            if ($reply_markup !== null) {
                $payload['reply_markup'] = is_string($reply_markup) ? $reply_markup : json_encode($reply_markup);
            }
            $telegram->sendMessage($payload);
            return;
        }

        $offset = 0;
        $first = true;
        while ($offset < $len) {
            if (function_exists('mb_substr')) {
                $chunk = mb_substr($text, $offset, $limit, 'UTF-8');
            } else {
                $chunk = substr($text, $offset, $limit);
            }

            $payload = [
                'chat_id' => $chat_id,
                'text' => $chunk,
                'parse_mode' => $parse_mode,
                'disable_web_page_preview' => true,
            ];
            if ($first && $reply_markup !== null) {
                $payload['reply_markup'] = is_string($reply_markup) ? $reply_markup : json_encode($reply_markup);
            }
            $telegram->sendMessage($payload);

            $offset += $limit;
            $first = false;
        }
    }
}

/* =========================================================
 * Section: Core update parsing / extraction
 * ========================================================= */

if (!function_exists('tg_extract_ids')) {
    function tg_extract_ids($data)
    {
        $chat_id = $from_id = $sender_chat_id = null;

        if (isset($data['message'])) {
            $m = $data['message'];
            $chat_id        = isset($m['chat']['id']) ? $m['chat']['id'] : null;
            $from_id        = isset($m['from']['id']) ? $m['from']['id'] : null;
            $sender_chat_id = isset($m['sender_chat']['id']) ? $m['sender_chat']['id'] : null;
        } elseif (isset($data['edited_message'])) {
            $m = $data['edited_message'];
            $chat_id        = isset($m['chat']['id']) ? $m['chat']['id'] : null;
            $from_id        = isset($m['from']['id']) ? $m['from']['id'] : null;
            $sender_chat_id = isset($m['sender_chat']['id']) ? $m['sender_chat']['id'] : null;
        } elseif (isset($data['channel_post'])) {
            $m = $data['channel_post'];
            $chat_id        = isset($m['chat']['id']) ? $m['chat']['id'] : null;
            $from_id        = isset($m['from']['id']) ? $m['from']['id'] : null;
            $sender_chat_id = isset($m['sender_chat']['id']) ? $m['sender_chat']['id'] : null;
        } elseif (isset($data['edited_channel_post'])) {
            $m = $data['edited_channel_post'];
            $chat_id        = isset($m['chat']['id']) ? $m['chat']['id'] : null;
            $from_id        = isset($m['from']['id']) ? $m['from']['id'] : null;
            $sender_chat_id = isset($m['sender_chat']['id']) ? $m['sender_chat']['id'] : null;
        } elseif (isset($data['callback_query'])) {
            $cb  = $data['callback_query'];
            $msg = isset($cb['message']) && is_array($cb['message']) ? $cb['message'] : [];
            $chat_id        = isset($msg['chat']['id']) ? $msg['chat']['id'] : null;
            $from_id        = isset($cb['from']['id']) ? $cb['from']['id'] : null;
            $sender_chat_id = isset($msg['sender_chat']['id']) ? $msg['sender_chat']['id'] : null;
        } elseif (isset($data['inline_query'])) {
            $from_id = isset($data['inline_query']['from']['id']) ? $data['inline_query']['from']['id'] : null;
        } elseif (isset($data['my_chat_member'])) {
            $mcm = $data['my_chat_member'];
            $chat_id = isset($mcm['chat']['id']) ? $mcm['chat']['id'] : null;
            $from_id = isset($mcm['from']['id']) ? $mcm['from']['id'] : null;
        }

        $chat_id        = $chat_id !== null ? strval($chat_id) : null;
        $from_id        = $from_id !== null ? strval($from_id) : null;
        $sender_chat_id = $sender_chat_id !== null ? strval($sender_chat_id) : null;

        $actor_id = $from_id ? $from_id : $sender_chat_id;

        return [$chat_id, $actor_id];
    }
}

if (!function_exists('tg_access_allowed')) {
    function tg_access_allowed($data, $ALLOWED_USERS, $ALLOWED_CHATS, $CHAT_ACL, $is_cron_request = false)
    {
        if ($is_cron_request) {
            return true;
        }

        // Open by default if ACL is not configured.
        if (empty($ALLOWED_USERS) && empty($ALLOWED_CHATS) && empty($CHAT_ACL)) {
            return true;
        }

        list($chat_id, $actor_id) = tg_extract_ids($data);

        if ($actor_id && in_array($actor_id, $ALLOWED_USERS, true)) {
            return true;
        }

        if ($chat_id && in_array($chat_id, $ALLOWED_CHATS, true)) {
            return true;
        }

        if ($chat_id && isset($CHAT_ACL[$chat_id])) {
            $rule = $CHAT_ACL[$chat_id];
            if (is_array($rule)) {
                if (in_array('*', $rule, true)) {
                    return true;
                }
                if ($actor_id && in_array($actor_id, $rule, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('tg_detect_update_type')) {
    function tg_detect_update_type($data)
    {
        $priority = [
            'callback_query',
            'message',
            'edited_message',
            'channel_post',
            'edited_channel_post',
            'inline_query',
            'chosen_inline_result',
            'my_chat_member',
            'chat_member',
            'chat_join_request',
        ];

        foreach ($priority as $type) {
            if (isset($data[$type])) {
                return $type;
            }
        }

        return 'unknown';
    }
}

if (!function_exists('tg_build_context')) {
    function tg_build_context($telegram, $data)
    {
        $update_type = tg_detect_update_type($data);
        list($chat_id, $actor_id) = tg_extract_ids($data);

        $text = '';
        $caption = '';
        $callback_data = '';
        $callback_query_id = null;
        $message_id = null;
        $web_app_data = null;

        if ($update_type === 'message') {
            $msg = $data['message'];
            $text = isset($msg['text']) ? (string) $msg['text'] : '';
            $caption = isset($msg['caption']) ? (string) $msg['caption'] : '';
            $message_id = isset($msg['message_id']) ? $msg['message_id'] : null;
            if (isset($msg['web_app_data']['data'])) {
                $web_app_data = $msg['web_app_data']['data'];
            }
        } elseif ($update_type === 'callback_query') {
            $cb = $data['callback_query'];
            $callback_data = isset($cb['data']) ? (string) $cb['data'] : '';
            $callback_query_id = isset($cb['id']) ? $cb['id'] : null;
            $message_id = isset($cb['message']['message_id']) ? $cb['message']['message_id'] : null;
        }

        $first_name = '';
        $last_name = '';
        $username = '';
        $language_code = '';

        if ($update_type === 'callback_query' && isset($data['callback_query']['from'])) {
            $from = $data['callback_query']['from'];
        } elseif (isset($data['message']['from'])) {
            $from = $data['message']['from'];
        } else {
            $from = [];
        }

        if (is_array($from)) {
            $first_name = isset($from['first_name']) ? (string) $from['first_name'] : '';
            $last_name = isset($from['last_name']) ? (string) $from['last_name'] : '';
            $username = isset($from['username']) ? (string) $from['username'] : '';
            $language_code = isset($from['language_code']) ? strtolower((string) $from['language_code']) : '';
        }

        return [
            'update_type' => $update_type,
            'chat_id' => $chat_id,
            'actor_id' => $actor_id,
            'text' => $text,
            'caption' => $caption,
            'callback_data' => $callback_data,
            'callback_query_id' => $callback_query_id,
            'message_id' => $message_id,
            'web_app_data' => $web_app_data,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'username' => $username,
            'language_code' => $language_code,
        ];
    }
}

/* =========================================================
 * Section: Profile / avatar helpers
 * ========================================================= */

if (!function_exists('tg_get_user_avatar')) {
    function tg_get_user_avatar($telegram, $user_id, $username = 'user', $message_id = null, $target_dir = null)
    {
        $result = [
            'file_id' => null,
            'file_patch_avatar' => 'no_avatar.png',
            'saved' => false,
            'saved_path' => null,
        ];

        if (!$telegram || empty($user_id)) {
            return $result;
        }

        $content = ['user_id' => $user_id, 'offset' => 0, 'limit' => 1];
        $photos = $telegram->getUserProfilePhotos($content);

        if (empty($photos['result']['photos'][0][0]['file_id'])) {
            return $result;
        }

        $file_id = $photos['result']['photos'][0][0]['file_id'];
        $result['file_id'] = $file_id;

        $suffix = $message_id !== null ? (string) $message_id : (string) time();
        $safe_username = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $username);
        if ($safe_username === '') {
            $safe_username = 'user';
        }
        $file_name = 'avatar_' . $safe_username . '_' . $suffix . '.png';
        $result['file_patch_avatar'] = $file_name;

        $file = $telegram->getFile($file_id);
        if (empty($file['result']['file_path'])) {
            return $result;
        }

        if ($target_dir === null) {
            if (function_exists('get_template_directory')) {
                $target_dir = rtrim(get_template_directory(), '/') . '/uploadbotfiles/avatars/';
            } else {
                $target_dir = dirname(__DIR__, 4) . '/uploadbotfiles/avatars/';
            }
        }

        if (!is_dir($target_dir)) {
            @mkdir($target_dir, 0775, true);
        }

        $full_path = rtrim($target_dir, '/') . '/' . $file_name;
        $ok = $telegram->downloadFile($file['result']['file_path'], $full_path);
        $result['saved'] = (bool) $ok;
        $result['saved_path'] = $full_path;

        return $result;
    }
}

if (!function_exists('mcx_b')) {
    function mcx_b($text)
    {
        return '*' . $text . '*';
    }
}

if (!function_exists('mcx_i')) {
    function mcx_i($text)
    {
        return '_' . $text . '_';
    }
}

if (!function_exists('mcx_copy')) {
    function mcx_copy($t)
    {
        return '`' . $t . '`';
    }
}

if (!function_exists('mcx_profile_card')) {
    function mcx_profile_card($code, $deeplink, $email, $tel, $bank, $acc_no, $acc_name, $bal_main, $bal_bonus)
    {
        $e = "\r\n";
        $sum = (float) $bal_main + (float) $bal_bonus;

        $s  = 'Profile' . $e . $e;
        $s .= 'Referral code: ' . mcx_copy($code) . $e;
        $s .= 'Referral link: ' . $deeplink . $e . $e;
        $s .= 'Email: ' . $email . $e;
        $s .= 'Phone: ' . $tel . $e;
        $s .= 'Bank: ' . $bank . $e;
        $s .= 'Account number: ' . $acc_no . $e;
        $s .= 'Account name: ' . $acc_name . $e . $e;
        $s .= 'Main balance: ' . $bal_main . ' THB' . $e;
        $s .= 'Bonus balance: ' . $bal_bonus . ' THB' . $e;
        $s .= 'Total: ' . $sum . ' THB';

        return $s;
    }
}

if (!function_exists('mcx_extract_start_param')) {
    function mcx_extract_start_param($text)
    {
        if (!$text) {
            return null;
        }
        if (strpos($text, '/start') !== 0) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($text), 2);
        if (isset($parts[1]) && $parts[1] !== '') {
            return trim($parts[1]);
        }

        return null;
    }
}

/* =========================================================
 * Section: Reusable utility functions from legacy callbacks
 * ========================================================= */

if (!function_exists('calculateProfitKind')) {
    function calculateProfitKind($finalValue, $percentage)
    {
        if ($percentage < 0 || $percentage > 100) {
            return 'Error: invalid percentage';
        }

        $originalValue = $finalValue / (1 + ($percentage / 100));
        return $finalValue - $originalValue;
    }
}

if (!function_exists('transliterateRussianNames')) {
    function transliterateRussianNames($string)
    {
        if (class_exists('Transliterator')) {
            $transliterator = Transliterator::create('Russian-Latin/BGN');
            if ($transliterator) {
                return $transliterator->transliterate($string);
            }
        }

        // Fallback transliteration map.
        $map = [
            'А' => 'A',
            'Б' => 'B',
            'В' => 'V',
            'Г' => 'G',
            'Д' => 'D',
            'Е' => 'E',
            'Ё' => 'E',
            'Ж' => 'Zh',
            'З' => 'Z',
            'И' => 'I',
            'Й' => 'Y',
            'К' => 'K',
            'Л' => 'L',
            'М' => 'M',
            'Н' => 'N',
            'О' => 'O',
            'П' => 'P',
            'Р' => 'R',
            'С' => 'S',
            'Т' => 'T',
            'У' => 'U',
            'Ф' => 'F',
            'Х' => 'Kh',
            'Ц' => 'Ts',
            'Ч' => 'Ch',
            'Ш' => 'Sh',
            'Щ' => 'Sch',
            'Ъ' => '',
            'Ы' => 'Y',
            'Ь' => '',
            'Э' => 'E',
            'Ю' => 'Yu',
            'Я' => 'Ya',
            'а' => 'a',
            'б' => 'b',
            'в' => 'v',
            'г' => 'g',
            'д' => 'd',
            'е' => 'e',
            'ё' => 'e',
            'ж' => 'zh',
            'з' => 'z',
            'и' => 'i',
            'й' => 'y',
            'к' => 'k',
            'л' => 'l',
            'м' => 'm',
            'н' => 'n',
            'о' => 'o',
            'п' => 'p',
            'р' => 'r',
            'с' => 's',
            'т' => 't',
            'у' => 'u',
            'ф' => 'f',
            'х' => 'kh',
            'ц' => 'ts',
            'ч' => 'ch',
            'ш' => 'sh',
            'щ' => 'sch',
            'ъ' => '',
            'ы' => 'y',
            'ь' => '',
            'э' => 'e',
            'ю' => 'yu',
            'я' => 'ya',
        ];

        return strtr((string) $string, $map);
    }
}

if (!function_exists('generateRandomString')) {
    function generateRandomString()
    {
        $segments = [8, 4, 4, 4, 12];
        $out = [];

        foreach ($segments as $len) {
            $part = '';
            for ($i = 0; $i < $len; $i++) {
                $part .= dechex(random_int(0, 15));
            }
            $out[] = $part;
        }

        return implode('-', $out);
    }
}

if (!function_exists('roundToTen')) {
    function roundToTen($number)
    {
        return round($number, -1);
    }
}

if (!function_exists('roundUpToNearest100')) {
    function roundUpToNearest100($number)
    {
        $remainder = $number % 100;
        if ($remainder === 0) {
            return $number;
        }
        return $number + (100 - $remainder);
    }
}

if (!function_exists('startsWith')) {
    function startsWith($string, $prefix)
    {
        return strpos((string) $string, (string) $prefix) === 0;
    }
}

if (!function_exists('removePrefix')) {
    function removePrefix($string, $prefix)
    {
        if (startsWith($string, $prefix)) {
            return substr($string, strlen($prefix));
        }
        return $string;
    }
}

if (!function_exists('formatRate')) {
    function formatRate($rate, $decimals = 5)
    {
        $original = rtrim(rtrim(number_format((float) $rate, 10, '.', ''), '0'), '.');
        $formatted = rtrim(rtrim(number_format((float) $rate, (int) $decimals, '.', ''), '0'), '.');
        $is_approx = ($original !== $formatted);

        return ($is_approx ? 'approx ' : '') . $formatted;
    }
}

if (!function_exists('isQrMethod')) {
    function isQrMethod($method)
    {
        return strtoupper((string) $method) === 'QR';
    }
}

if (!function_exists('applyQrMarkupRub')) {
    function applyQrMarkupRub($rubAmount, $k = 1.025)
    {
        $val = (float) $rubAmount * (float) $k;
        return round($val * 10) / 10;
    }
}

if (!function_exists('isRUB')) {
    function isRUB($currency)
    {
        return strtoupper((string) $currency) === 'RUB';
    }
}

if (!function_exists('parseRatesFromMessage')) {
    function parseRatesFromMessage($text)
    {
        $text = html_entity_decode((string) $text);

        $patterns = [
            'priem'  => '/(?:Прием(?:\s+рубл[ея]|\s+рубля)?|Priem(?:\s+rubl[ea]|\s+rublya)?)[\s\S]{0,120}?([0-9]+(?:[.,][0-9]+)?)/iu',
            'oplata' => '/(?:Оплата(?:\s+по\s+QR(?:\/ссылке)?|(?:\s+по\s+)?QR\/ссылке|QR|по\s+QR\/ссылке)?|Oplata(?:\s+po\s+QR(?:\/ssylke)?|(?:\s+po\s+)?QR\/ssylke|QR|po\s+QR\/ssylke)?)[\s\S]{0,120}?([0-9]+(?:[.,][0-9]+)?)/iu',
            'viplata' => '/(?:Выплат(?:ы|а)(?:\s+рубл[ея]|\s+рубля)?|Viplat(?:y|a)(?:\s+rubl[ea]|\s+rublya)?)[\s\S]{0,120}?([0-9]+(?:[.,][0-9]+)?)/iu',
        ];

        $rates = ['priem' => null, 'oplata' => null, 'viplata' => null];

        foreach ($patterns as $key => $pat) {
            if (preg_match($pat, $text, $m)) {
                $num = str_replace(',', '.', $m[1]);
                $num = preg_replace('/[^\d.]/', '', $num);
                if ($num !== '') {
                    $rates[$key] = (float) $num;
                }
            }
        }

        if (in_array(null, $rates, true)) {
            if (preg_match_all('/([0-9]+(?:[.,][0-9]+)?)/', $text, $all)) {
                $found = array_map(function ($s) {
                    return str_replace(',', '.', $s);
                }, $all[1]);

                $order = ['priem', 'oplata', 'viplata'];
                $i = 0;
                foreach ($order as $k) {
                    if ($rates[$k] === null && isset($found[$i])) {
                        $rates[$k] = (float) preg_replace('/[^\d.]/', '', $found[$i]);
                    }
                    $i++;
                }
            }
        }

        return $rates;
    }
}

if (!function_exists('parseFileCmd')) {
    function parseFileCmd($text)
    {
        if (preg_match('~^/file\s*([0-9]+)\s*(\S+)?~u', trim((string) $text), $m)) {
            $rowId = intval($m[1]);
            $url   = isset($m[2]) ? trim($m[2]) : null;
            return [$rowId, $url];
        }
        return [null, null];
    }
}

if (!function_exists('setWaitFileRowForStaff')) {
    function setWaitFileRowForStaff($chatId, $rowId)
    {
        $key = 'bot_wait_file_row_' . intval($chatId);
        if (function_exists('update_option')) {
            update_option($key, intval($rowId));
            return;
        }
        if (!isset($GLOBALS['tg_wait_file_rows']) || !is_array($GLOBALS['tg_wait_file_rows'])) {
            $GLOBALS['tg_wait_file_rows'] = [];
        }
        $GLOBALS['tg_wait_file_rows'][$key] = intval($rowId);
    }
}

if (!function_exists('getWaitFileRowForStaff')) {
    function getWaitFileRowForStaff($chatId)
    {
        $key = 'bot_wait_file_row_' . intval($chatId);
        if (function_exists('get_option')) {
            $v = get_option($key);
            return $v ? intval($v) : null;
        }
        if (isset($GLOBALS['tg_wait_file_rows'][$key])) {
            return intval($GLOBALS['tg_wait_file_rows'][$key]);
        }
        return null;
    }
}

if (!function_exists('clearWaitFileRowForStaff')) {
    function clearWaitFileRowForStaff($chatId)
    {
        $key = 'bot_wait_file_row_' . intval($chatId);
        if (function_exists('delete_option')) {
            delete_option($key);
            return;
        }
        if (isset($GLOBALS['tg_wait_file_rows'][$key])) {
            unset($GLOBALS['tg_wait_file_rows'][$key]);
        }
    }
}

if (!function_exists('delete_menu')) {
    function delete_menu($wpdb, $table_merchant_referal_users, $chat_id, $telegram)
    {
        if (!$wpdb || !$telegram || empty($table_merchant_referal_users) || empty($chat_id)) {
            return false;
        }

        $row = $wpdb->get_row("SELECT * FROM $table_merchant_referal_users WHERE chat_id = '$chat_id'");
        if ($row && !empty($row->last_menu_massage_id)) {
            $telegram->deleteMessage([
                'chat_id' => $chat_id,
                'message_id' => (int) $row->last_menu_massage_id,
            ]);
        }

        return true;
    }
}

if (!function_exists('tg_takeCourseFromLegacyExport')) {
    function tg_takeCourseFromLegacyExport($index)
    {
        $raw = @file_get_contents('https://moneyclub.cash/export_courses.xml?for_office');
        if (!is_string($raw) || $raw === '') {
            return false;
        }

        $parts = explode(',', $raw);
        if (!isset($parts[$index])) {
            return false;
        }

        $value = preg_replace('/[^\d.]/', '', $parts[$index]);
        return $value !== '' ? $value : false;
    }
}

if (!function_exists('takeCourseUSDT')) {
    function takeCourseUSDT()
    {
        return tg_takeCourseFromLegacyExport(2);
    }
}

if (!function_exists('takeCourseRub')) {
    function takeCourseRub()
    {
        return tg_takeCourseFromLegacyExport(0);
    }
}

if (!function_exists('checkqr_code')) {
    function checkqr_code($url)
    {
        $target = urlencode((string) $url);
        if ($target === '') {
            return false;
        }

        $api = 'https://api.qrserver.com/v1/read-qr-code/?fileurl=' . $target;
        $contents = @file_get_contents($api);
        if (!is_string($contents) || $contents === '') {
            return false;
        }

        $arr = json_decode($contents, true);
        if (!is_array($arr) || empty($arr[0]['symbol'][0]['data'])) {
            return false;
        }

        return $arr[0]['symbol'][0]['data'];
    }
}

/* =========================================================
 * Section: Generic HTTP helpers + QR helper
 * ========================================================= */

if (!function_exists('sendPostRequest')) {
    function sendPostRequest($url, $data)
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            fifo_bot_dbg('sendPostRequest curl error', ['error' => curl_error($ch), 'url' => $url]);
        }

        curl_close($ch);
        return $response;
    }
}

if (!function_exists('createQRcode')) {
    function createQRcode($payload, $qrcId, $orderId, $company_id = null, $baseDir = null, $baseUrl = null)
    {
        if (!tg_require_qrcode_lib()) {
            fifo_bot_dbg('createQRcode: QR library is not available');
            return false;
        }

        if ($baseDir === null) {
            if (function_exists('get_template_directory')) {
                $baseDir = rtrim(get_template_directory(), '/') . '/uploadbotfiles/';
            } else {
                $baseDir = dirname(__DIR__, 4) . '/uploadbotfiles/';
            }
        }

        if ($baseUrl === null) {
            if (function_exists('get_template_directory_uri')) {
                $baseUrl = rtrim(get_template_directory_uri(), '/') . '/uploadbotfiles/';
            } else {
                $baseUrl = rtrim($baseDir, '/') . '/';
            }
        }

        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0775, true);
        }

        $suffix = ($company_id !== null && $company_id !== '') ? ('_c' . $company_id) : '';
        $fileName = 'qr_' . $qrcId . '_' . $orderId . $suffix . '.png';

        $pngAbsoluteFilePath = rtrim($baseDir, '/') . '/' . $fileName;
        if (!file_exists($pngAbsoluteFilePath)) {
            QRcode::png((string) $payload, $pngAbsoluteFilePath);
        }

        if (preg_match('~^https?://~i', (string) $baseUrl)) {
            return rtrim($baseUrl, '/') . '/' . $fileName;
        }

        return $pngAbsoluteFilePath;
    }
}

/* =========================================================
 * Section: Kanyon adapters (safe wrappers)
 * ========================================================= */

if (!function_exists('loadRateUsdtKanyon')) {
    function loadRateUsdtKanyon($telegram = null, $debuging = false)
    {
        $url = defined('KANYON_CURRENCIES_URL')
            ? (string) KANYON_CURRENCIES_URL
            : 'https://lk.sbpay.pro/qr/api/v1/currencies?terminalKey=dearwhynot';

        $response = @file_get_contents($url);
        if (!is_string($response) || $response === '') {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $response = curl_exec($ch);
            curl_close($ch);
        }

        $data = json_decode((string) $response, true);
        $rate = null;
        if (is_array($data)) {
            foreach ($data as $item) {
                if (isset($item['purpose'])) {
                    $rate = $item['purpose'];
                    break;
                }
            }
        }

        if ($rate !== null) {
            return $rate;
        }

        if ($debuging && $telegram) {
            $telegram->sendMessage([
                'chat_id' => defined('TG_DEBUG_ADMIN_CHAT_ID') ? TG_DEBUG_ADMIN_CHAT_ID : '160457790',
                'text' => 'loadRateUsdtKanyon: failed to resolve rate',
            ]);
        }

        return false;
    }
}

if (!function_exists('sendPostRequestNew')) {
    function sendPostRequestNew($amount)
    {
        $url = defined('KANYON_ORDER_URL')
            ? (string) KANYON_ORDER_URL
            : 'https://kanyonpay.pay2day.kz/api/v1/public/order';

        $payload = [
            'merchantOrderId' => '',
            'orderCurrency' => 'USDT',
            'description' => '',
            'tspCode' => defined('KANYON_TSP_CODE') ? (string) KANYON_TSP_CODE : 'dearwhynot',
            'paymentCurrency' => 'RUB',
            'orderAmount' => $amount,
        ];

        $response = sendPostRequest($url, $payload);
        $decoded = is_string($response) ? json_decode($response, true) : null;

        if (!is_array($decoded) || !isset($decoded['order']) || !is_array($decoded['order'])) {
            return ['success' => false, 'raw' => $response];
        }

        return [
            'success' => !empty($decoded['success']),
            'id' => isset($decoded['order']['id']) ? $decoded['order']['id'] : null,
            'summ_rub' => isset($decoded['order']['paymentAmount']) ? $decoded['order']['paymentAmount'] : null,
            'raw' => $decoded,
        ];
    }
}

if (!function_exists('takeLink')) {
    function takeLink($id)
    {
        $url = defined('KANYON_QRC_URL_PREFIX')
            ? rtrim((string) KANYON_QRC_URL_PREFIX, '/') . '/' . $id
            : 'https://kanyonpay.pay2day.kz/api/v1/public/order/qrcData/dearwhynot/' . $id;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(null));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            fifo_bot_dbg('takeLink curl error', ['error' => curl_error($ch), 'url' => $url]);
        }

        curl_close($ch);
        return $response;
    }
}

if (!function_exists('kanyon_adapter_create_order')) {
    function kanyon_adapter_create_order($amount)
    {
        // External override point.
        if (function_exists('kanyon_create_order')) {
            return call_user_func('kanyon_create_order', $amount);
        }

        return sendPostRequestNew($amount);
    }
}

if (!function_exists('kanyon_adapter_get_qr_data')) {
    function kanyon_adapter_get_qr_data($order_id)
    {
        if (function_exists('kanyon_get_qr_data')) {
            return call_user_func('kanyon_get_qr_data', $order_id);
        }

        return takeLink($order_id);
    }
}

if (!function_exists('kanyon_adapter_handle_callback')) {
    function kanyon_adapter_handle_callback($callback_data, $ctx, $telegram)
    {
        if (!is_string($callback_data) || $callback_data === '') {
            return false;
        }

        if (strpos($callback_data, 'kanyon_paid:') === 0) {
            list(, $kanyon_id) = explode(':', $callback_data, 2);

            $handled = false;
            if (function_exists('kanyon_handle_paid')) {
                $handled = (bool) call_user_func('kanyon_handle_paid', $kanyon_id, $ctx, $telegram);
            }

            if (!$handled && !empty($ctx['chat_id'])) {
                bot_send_message($telegram, $ctx['chat_id'], 'Payment check request accepted for order ' . $kanyon_id);
            }

            tg_safe_answer_callback($telegram, $ctx['callback_query_id'], 'Checking payment...');
            return true;
        }

        if (strpos($callback_data, 'kanyon_cancel:') === 0) {
            list(, $kanyon_id) = explode(':', $callback_data, 2);

            $handled = false;
            if (function_exists('kanyon_handle_cancel')) {
                $handled = (bool) call_user_func('kanyon_handle_cancel', $kanyon_id, $ctx, $telegram);
            }

            if (!$handled && !empty($ctx['chat_id'])) {
                bot_send_message($telegram, $ctx['chat_id'], 'Order canceled: ' . $kanyon_id);
            }

            tg_safe_answer_callback($telegram, $ctx['callback_query_id'], 'Order canceled');
            return true;
        }

        return false;
    }
}

/* =========================================================
 * Section: Doverka adapters (safe wrappers)
 * ========================================================= */

if (!function_exists('doverka_safe_call')) {
    function doverka_safe_call($fn)
    {
        $args = func_get_args();
        array_shift($args);

        if (is_string($fn) && function_exists($fn)) {
            return call_user_func_array($fn, $args);
        }

        return null;
    }
}

if (!function_exists('doverka_adapter_handle_update')) {
    function doverka_adapter_handle_update($ctx, $telegram, $data)
    {
        if (function_exists('doverka_telegram_handle_update')) {
            return (bool) doverka_safe_call('doverka_telegram_handle_update', $ctx, $telegram, $data);
        }

        return false;
    }
}

if (!function_exists('doverka_adapter_handle_callback')) {
    function doverka_adapter_handle_callback($callback_data, $ctx, $telegram, $data)
    {
        if (strpos((string) $callback_data, 'doverka:') !== 0) {
            return false;
        }

        if (function_exists('doverka_telegram_handle_callback')) {
            return (bool) doverka_safe_call('doverka_telegram_handle_callback', $callback_data, $ctx, $telegram, $data);
        }

        // No integration function found: fail safely and keep callback alive.
        tg_safe_answer_callback($telegram, $ctx['callback_query_id'], 'Doverka adapter is not configured yet.');
        if (!empty($ctx['chat_id'])) {
            bot_send_message($telegram, $ctx['chat_id'], 'Doverka adapter is not configured in this project.');
        }

        return true;
    }
}

/* =========================================================
 * Section: Routing helpers
 * ========================================================= */

if (!function_exists('tg_help_text')) {
    function tg_help_text()
    {
        $e = "\r\n";
        $txt = 'Available commands:' . $e;
        $txt .= '/start - start dialog' . $e;
        $txt .= '/menu - open menu' . $e;
        $txt .= '/ping - endpoint ping' . $e;
        $txt .= '/chat_id - show chat id' . $e;
        $txt .= '/kanyon_rate - read Kanyon USDT rate' . $e;
        $txt .= '/help - this help';

        return $txt;
    }
}

if (!function_exists('tg_route_command')) {
    function tg_route_command($command, $text, $ctx, $telegram, $data)
    {
        $chat_id = $ctx['chat_id'];
        $actor_id = isset($ctx['actor_id']) ? $ctx['actor_id'] : null;

        if (!$chat_id) {
            return false;
        }

        if ($command === '/start') {
            if (!tg_universal_is_user_authorized_for_start_menu($actor_id, $chat_id)) {
                bot_send_message($telegram, $chat_id, '⛔ У вас нет доступа к рабочему меню бота. Обратитесь к администратору. Chat ID: ' . $chat_id . ', User ID: ' . ($actor_id !== null ? (string) $actor_id : 'n/a'));
                return true;
            }

            $reply = '✅ Бот активен. Рабочее меню загружено.';
            bot_send_message($telegram, $chat_id, $reply);
            fifo_bot_menu($telegram, $chat_id, $actor_id);
            return true;
        }

        if ($command === '/menu') {
            if (!tg_universal_is_user_authorized_for_start_menu($actor_id, $chat_id)) {
                bot_send_message($telegram, $chat_id, '⛔ У вас нет доступа к рабочему меню бота. Обратитесь к администратору. Chat ID: ' . $chat_id . ', User ID: ' . ($actor_id !== null ? (string) $actor_id : 'n/a'));
                return true;
            }
            fifo_bot_menu($telegram, $chat_id, $actor_id);
            return true;
        }

        if ($command === '/help') {
            bot_send_message($telegram, $chat_id, nl2br(tg_help_text()));
            return true;
        }

        if ($command === '/ping') {
            bot_send_message($telegram, $chat_id, 'pong ' . gmdate('Y-m-d H:i:s') . ' UTC');
            return true;
        }

        if ($command === '/chat_id') {
            bot_send_message($telegram, $chat_id, 'chat_id: ' . $chat_id . '; user_id: ' . ($actor_id !== null ? (string) $actor_id : 'n/a'));
            return true;
        }

        if ($command === '/kanyon_rate') {
            $rate = loadRateUsdtKanyon($telegram, false);
            if ($rate === false) {
                bot_send_message($telegram, $chat_id, 'Kanyon rate is not available now.');
            } else {
                bot_send_message($telegram, $chat_id, 'Kanyon USDT rate: ' . $rate);
            }
            return true;
        }

        return false;
    }
}

if (!function_exists('tg_route_callback')) {
    function tg_route_callback($callback_data, $ctx, $telegram, $data)
    {
        $chat_id = $ctx['chat_id'];
        $actor_id = isset($ctx['actor_id']) ? $ctx['actor_id'] : null;

        $orders_actions = [
            'orders_refresh_rate' => '🔄 Команда: обновить курс',
            'orders_new' => '🆕 Команда: новый ордер',
            'orders_open' => '📂 Команда: список открытых ордеров',
            'orders_closed' => '✅ Команда: список закрытых ордеров',
            'orders_canceled' => '❌ Команда: список отмененных ордеров',
        ];

        if (isset($orders_actions[$callback_data])) {
            if (!tg_universal_is_user_authorized_for_start_menu($actor_id, $chat_id)) {
                tg_safe_answer_callback($telegram, $ctx['callback_query_id'], 'Нет доступа');
                if (!empty($chat_id)) {
                    bot_send_message($telegram, $chat_id, '⛔ Доступ к этой команде запрещен. Chat ID: ' . $chat_id . ', User ID: ' . ($actor_id !== null ? (string) $actor_id : 'n/a'));
                }
                return true;
            }

            tg_safe_answer_callback($telegram, $ctx['callback_query_id'], 'Выполняю...');

            $project_handled = false;
            if (function_exists('tg_project_handle_orders_action')) {
                $project_handled = (bool) tg_call_project_handler(
                    'tg_project_handle_orders_action',
                    $callback_data,
                    $ctx,
                    $telegram,
                    $data
                );
            }

            if (!$project_handled && !empty($chat_id)) {
                bot_send_message($telegram, $chat_id, $orders_actions[$callback_data]);
            }

            return true;
        }

        if ($callback_data === 'menu_main') {
            fifo_bot_menu($telegram, $chat_id, $actor_id);
            tg_safe_answer_callback($telegram, $ctx['callback_query_id'], 'Menu');
            return true;
        }

        if ($callback_data === 'menu_ping') {
            bot_send_message($telegram, $chat_id, 'pong ' . gmdate('Y-m-d H:i:s') . ' UTC');
            tg_safe_answer_callback($telegram, $ctx['callback_query_id'], 'Pong');
            return true;
        }

        if ($callback_data === 'menu_help') {
            bot_send_message($telegram, $chat_id, nl2br(tg_help_text()));
            tg_safe_answer_callback($telegram, $ctx['callback_query_id'], 'Help');
            return true;
        }

        if (kanyon_adapter_handle_callback($callback_data, $ctx, $telegram)) {
            return true;
        }

        if (doverka_adapter_handle_callback($callback_data, $ctx, $telegram, $data)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('tg_handle_web_app_data')) {
    function tg_handle_web_app_data($ctx, $telegram)
    {
        if (empty($ctx['chat_id']) || $ctx['web_app_data'] === null) {
            return false;
        }

        $decoded = json_decode((string) $ctx['web_app_data'], true);
        if (is_array($decoded)) {
            bot_send_message($telegram, $ctx['chat_id'], 'WebApp data received.');
            fifo_bot_dbg('web_app_data', $decoded);
        } else {
            bot_send_message($telegram, $ctx['chat_id'], 'WebApp payload received.');
            fifo_bot_dbg('web_app_data_raw', ['payload' => $ctx['web_app_data']]);
        }

        return true;
    }
}

if (!function_exists('tg_call_project_handler')) {
    function tg_call_project_handler($fn)
    {
        $args = func_get_args();
        array_shift($args);

        if (is_string($fn) && function_exists($fn)) {
            return call_user_func_array($fn, $args);
        }

        return null;
    }
}

/* =========================================================
 * Section: Main dispatch logic
 * ========================================================= */

if (!function_exists('tg_universal_callback_dispatch')) {
    function tg_universal_callback_dispatch()
    {
        global $TG_ALLOWED_USERS;
        global $TG_ALLOWED_CHATS;
        global $TG_CHAT_ACL;
        global $TG_UNDER_CONSTRUCTION_MODE;
        global $TG_UNDER_CONSTRUCTION_TEXT;

        $request_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($request_method !== 'POST') {
            return [
                'status' => 200,
                'content_type' => 'text/plain; charset=utf-8',
                'body' => "Telegram callback endpoint is alive\n"
                    . 'Method: ' . $request_method . "\n"
                    . 'Time(UTC): ' . gmdate('Y-m-d H:i:s') . "\n",
            ];
        }

        if (!tg_require_telegram_class()) {
            fifo_bot_dbg('Telegram.php not found');
            return [
                'status' => 500,
                'content_type' => 'text/plain; charset=utf-8',
                'body' => 'Telegram class is missing',
            ];
        }

        $bot_token = tg_resolve_bot_token();
        if ($bot_token === '') {
            fifo_bot_dbg('bot token missing');
            return [
                'status' => 200,
                'content_type' => 'text/plain; charset=utf-8',
                'body' => 'OK',
            ];
        }

        $telegram = new Telegram($bot_token);
        $data = $telegram->getData();
        if (!is_array($data) || empty($data)) {
            $raw = tg_get_raw_update();
            $data = tg_decode_update($raw);
        }
        if (!is_array($data)) {
            $data = [];
        }

        $is_cron_request = (
            isset($_POST['coded'], $_POST['type'])
            && $_POST['coded'] === 'red'
            && $_POST['type'] === 'refresh'
        );

        if (empty($data) && $is_cron_request) {
            tg_call_project_handler('tg_handle_cron_request', $telegram);
            return [
                'status' => 200,
                'content_type' => 'text/plain; charset=utf-8',
                'body' => 'OK',
            ];
        }

        if (empty($data)) {
            fifo_bot_dbg('empty update body');
            return [
                'status' => 200,
                'content_type' => 'text/plain; charset=utf-8',
                'body' => 'OK',
            ];
        }

        if (!tg_access_allowed($data, $TG_ALLOWED_USERS, $TG_ALLOWED_CHATS, $TG_CHAT_ACL, $is_cron_request)) {
            fifo_bot_dbg('access denied', ['ids' => tg_extract_ids($data)]);
            return [
                'status' => 200,
                'content_type' => 'text/plain; charset=utf-8',
                'body' => 'OK',
            ];
        }

        $ctx = tg_build_context($telegram, $data);
        fifo_bot_dbg('context', [
            'update_type' => $ctx['update_type'],
            'chat_id' => $ctx['chat_id'],
            'actor_id' => $ctx['actor_id'],
            'callback_data' => $ctx['callback_data'],
        ]);

        if ($TG_UNDER_CONSTRUCTION_MODE && !empty($ctx['chat_id'])) {
            bot_send_message($telegram, $ctx['chat_id'], $TG_UNDER_CONSTRUCTION_TEXT);
            return [
                'status' => 200,
                'content_type' => 'text/plain; charset=utf-8',
                'body' => 'OK',
            ];
        }

        $handled = false;

        // 1) Project-wide adapter hooks first.
        if (!$handled) {
            $handled = (bool) doverka_adapter_handle_update($ctx, $telegram, $data);
        }

        if (!$handled && function_exists('tg_project_handle_update')) {
            $handled = (bool) tg_call_project_handler('tg_project_handle_update', $ctx, $telegram, $data);
        }

        // 2) Callback query route.
        if (!$handled && $ctx['update_type'] === 'callback_query') {
            $handled = tg_route_callback($ctx['callback_data'], $ctx, $telegram, $data);

            if (!$handled) {
                $handled = (bool) tg_call_project_handler('tg_project_handle_callback', $ctx['callback_data'], $ctx, $telegram, $data);
            }

            if (!$handled) {
                tg_safe_answer_callback($telegram, $ctx['callback_query_id'], 'Action is not configured yet.');
                if (!empty($ctx['chat_id'])) {
                    bot_send_message($telegram, $ctx['chat_id'], 'Action is not configured yet.');
                }
                $handled = true;
            }
        }

        // 3) Message route.
        if (!$handled && $ctx['update_type'] === 'message') {
            if ($ctx['web_app_data'] !== null) {
                $handled = tg_handle_web_app_data($ctx, $telegram);
            }

            if (!$handled) {
                $text = trim((string) $ctx['text']);

                if ($text !== '' && $text[0] === '/') {
                    $command = preg_split('/\s+/', $text, 2)[0];
                    $command = strtolower(preg_replace('/@[^\s]+$/', '', $command));
                    $handled = tg_route_command($command, $text, $ctx, $telegram, $data);
                }

                if (!$handled && function_exists('tg_project_handle_message')) {
                    $handled = (bool) tg_call_project_handler('tg_project_handle_message', $text, $ctx, $telegram, $data);
                }

                if (!$handled && !empty($ctx['chat_id'])) {
                    bot_send_message($telegram, $ctx['chat_id'], 'Message received. Use /menu or /help.');
                    $handled = true;
                }
            }
        }

        // 4) Fallback for unsupported update types.
        if (!$handled && !empty($ctx['chat_id'])) {
            bot_send_message($telegram, $ctx['chat_id'], 'Update received but no handler is configured for this type.');
        }

        return [
            'status' => 200,
            'content_type' => 'text/plain; charset=utf-8',
            'body' => 'OK',
        ];
    }
}

if (!function_exists('tg_universal_callback_emit_response')) {
    function tg_universal_callback_emit_response($response)
    {
        $status = isset($response['status']) ? (int) $response['status'] : 200;
        $content_type = isset($response['content_type']) ? (string) $response['content_type'] : 'text/plain; charset=utf-8';
        $body = isset($response['body']) ? (string) $response['body'] : 'OK';

        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: ' . $content_type);
        }
        echo $body;
    }
}

if (!defined('TG_UNIVERSAL_EMBEDDED') || !TG_UNIVERSAL_EMBEDDED) {
    tg_universal_callback_emit_response(tg_universal_callback_dispatch());
    exit;
}

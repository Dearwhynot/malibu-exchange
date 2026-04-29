<?php

if (!class_exists('Telegram')) {
    class Telegram
    {
        private $bot_token = '';
        private $api_url = '';

        public function __construct($bot_token = '')
        {
            $this->bot_token = trim((string) $bot_token);
            $this->api_url = 'https://api.telegram.org/bot' . $this->bot_token . '/';
        }

        public function getData()
        {
            $raw = '';
            if (isset($GLOBALS['TG_UNIVERSAL_RAW_UPDATE']) && is_string($GLOBALS['TG_UNIVERSAL_RAW_UPDATE'])) {
                $raw = $GLOBALS['TG_UNIVERSAL_RAW_UPDATE'];
            }

            if ($raw === '') {
                $raw = @file_get_contents('php://input');
            }

            if (!is_string($raw) || $raw === '') {
                return [];
            }

            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        public function sendMessage($payload = [])
        {
            return $this->apiRequest('sendMessage', (array) $payload);
        }

        public function answerCallbackQuery($payload = [])
        {
            return $this->apiRequest('answerCallbackQuery', (array) $payload);
        }

        public function editMessageText($payload = [])
        {
            return $this->apiRequest('editMessageText', (array) $payload);
        }

        public function sendPhoto($payload = [])
        {
            return $this->apiRequest('sendPhoto', (array) $payload);
        }

        public function getFile($payload = [])
        {
            if (!is_array($payload)) {
                $payload = [
                    'file_id' => (string) $payload,
                ];
            }

            return $this->apiRequest('getFile', (array) $payload);
        }

        public function downloadFile($file_path, $destination_path)
        {
            $file_path = ltrim((string) $file_path, '/');
            $destination_path = (string) $destination_path;

            if (function_exists('tg_avatar_dbg')) {
                tg_avatar_dbg('Telegram::downloadFile:start', [
                    'file_path' => $file_path,
                    'destination_path' => $destination_path,
                ]);
            }

            if ($this->bot_token === '' || $file_path === '' || $destination_path === '') {
                if (function_exists('tg_avatar_dbg')) {
                    tg_avatar_dbg('Telegram::downloadFile:invalid_input', [
                        'has_bot_token' => $this->bot_token !== '',
                        'file_path' => $file_path,
                        'destination_path' => $destination_path,
                    ]);
                }
                return false;
            }

            $url = 'https://api.telegram.org/file/bot' . $this->bot_token . '/' . $file_path;
            $dir = dirname($destination_path);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                if (function_exists('tg_avatar_dbg')) {
                    tg_avatar_dbg('Telegram::downloadFile:mkdir_failed', [
                        'dir' => $dir,
                    ]);
                }
                return false;
            }

            if (function_exists('wp_remote_get')) {
                $response = wp_remote_get($url, [
                    'timeout' => 20,
                ]);

                if (is_wp_error($response)) {
                    if (function_exists('tg_avatar_dbg')) {
                        tg_avatar_dbg('Telegram::downloadFile:wp_remote_get_error', [
                            'file_path' => $file_path,
                            'error' => $response->get_error_message(),
                        ]);
                    }
                    return false;
                }

                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code < 200 || $code >= 300) {
                    if (function_exists('tg_avatar_dbg')) {
                        tg_avatar_dbg('Telegram::downloadFile:http_error', [
                            'file_path' => $file_path,
                            'http_code' => $code,
                        ]);
                    }
                    return false;
                }

                $body = wp_remote_retrieve_body($response);
                if (!is_string($body) || $body === '') {
                    if (function_exists('tg_avatar_dbg')) {
                        tg_avatar_dbg('Telegram::downloadFile:empty_body', [
                            'file_path' => $file_path,
                            'http_code' => $code,
                        ]);
                    }
                    return false;
                }

                $written = file_put_contents($destination_path, $body);
                $ok = $written !== false;
                if (function_exists('tg_avatar_dbg')) {
                    tg_avatar_dbg('Telegram::downloadFile:wp_remote_get_done', [
                        'file_path' => $file_path,
                        'http_code' => $code,
                        'bytes_received' => strlen($body),
                        'bytes_written' => $ok ? (int) $written : null,
                        'saved' => $ok,
                        'destination_exists' => is_file($destination_path),
                    ]);
                }

                return $ok;
            }

            if (!function_exists('curl_init')) {
                if (function_exists('tg_avatar_dbg')) {
                    tg_avatar_dbg('Telegram::downloadFile:curl_missing', [
                        'file_path' => $file_path,
                    ]);
                }
                return false;
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);

            $body = curl_exec($ch);
            if ($body === false) {
                $error = curl_error($ch);
                curl_close($ch);
                if (function_exists('tg_avatar_dbg')) {
                    tg_avatar_dbg('Telegram::downloadFile:curl_error', [
                        'file_path' => $file_path,
                        'error' => (string) $error,
                    ]);
                }
                return false;
            }

            $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code < 200 || $http_code >= 300 || !is_string($body) || $body === '') {
                if (function_exists('tg_avatar_dbg')) {
                    tg_avatar_dbg('Telegram::downloadFile:curl_http_error', [
                        'file_path' => $file_path,
                        'http_code' => $http_code,
                        'body_length' => is_string($body) ? strlen($body) : null,
                    ]);
                }
                return false;
            }

            $written = file_put_contents($destination_path, $body);
            $ok = $written !== false;
            if (function_exists('tg_avatar_dbg')) {
                tg_avatar_dbg('Telegram::downloadFile:curl_done', [
                    'file_path' => $file_path,
                    'http_code' => $http_code,
                    'bytes_received' => strlen($body),
                    'bytes_written' => $ok ? (int) $written : null,
                    'saved' => $ok,
                    'destination_exists' => is_file($destination_path),
                ]);
            }

            return $ok;
        }

        public function __call($method, $arguments)
        {
            $payload = [];
            if (isset($arguments[0]) && is_array($arguments[0])) {
                $payload = $arguments[0];
            }

            return $this->apiRequest((string) $method, $payload);
        }

        private function apiRequest($method, array $payload = [])
        {
            if ($this->bot_token === '') {
                return [
                    'ok' => false,
                    'description' => 'Bot token is missing',
                ];
            }

            $url = $this->api_url . ltrim((string) $method, '/');

            if (function_exists('wp_remote_post')) {
                $response = wp_remote_post($url, [
                    'timeout' => 20,
                    'body' => $payload,
                ]);

                if (is_wp_error($response)) {
                    return [
                        'ok' => false,
                        'description' => $response->get_error_message(),
                    ];
                }

                $body = wp_remote_retrieve_body($response);
                return $this->decodeResponse($body);
            }

            if (!function_exists('curl_init')) {
                return [
                    'ok' => false,
                    'description' => 'curl extension is missing',
                ];
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);

            $body = curl_exec($ch);
            if ($body === false) {
                $error = curl_error($ch);
                curl_close($ch);
                return [
                    'ok' => false,
                    'description' => (string) $error,
                ];
            }
            curl_close($ch);

            return $this->decodeResponse($body);
        }

        private function decodeResponse($body)
        {
            if (!is_string($body) || $body === '') {
                return [
                    'ok' => false,
                    'description' => 'Empty response',
                ];
            }

            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return [
                'ok' => false,
                'description' => 'Invalid JSON response',
                'raw' => $body,
            ];
        }
    }
}

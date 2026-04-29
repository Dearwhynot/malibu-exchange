-- ============================================================
-- Malibu Exchange — CRM Settings
-- Запустить один раз в phpMyAdmin или любом MySQL-клиенте.
-- Безопасно перезапускать: CREATE IF NOT EXISTS + INSERT IGNORE.
-- ============================================================
--
-- Таблица crm_settings хранит настройки системы в разрезе организаций.
-- org_id = 1 — организация по умолчанию (первая и единственная пока).
-- В будущем при добавлении организаций каждая получает свой набор настроек.
--
-- Формат: ключ-значение (setting_key / setting_value).
-- Все чувствительные данные (токены, пароли) хранятся здесь, а не в коде.
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_settings` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `org_id`        INT UNSIGNED     NOT NULL DEFAULT 1,
  `setting_key`   VARCHAR(128)     NOT NULL,
  `setting_value` TEXT             DEFAULT NULL,
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_key` (`org_id`, `setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Seed: дефолтные настройки для org_id = 1 ───────────────────────────────

INSERT IGNORE INTO `crm_settings` (`org_id`, `setting_key`, `setting_value`) VALUES
(1, 'telegram_bot_token', ''),
(1, 'telegram_bot_username', ''),
(1, 'telegram_webhook_url', ''),
(1, 'telegram_webhook_connected_at', ''),
(1, 'telegram_webhook_last_error', ''),
(1, 'telegram_webhook_lock', '0'),
(1, 'fintech_allowed_providers', '["kanyon","doverka"]'),
(1, 'merchant_invite_ttl_minutes', '60'),
(1, 'merchant_default_platform_fee_type', 'percent'),
(1, 'merchant_default_platform_fee_value', '0'),
(1, 'merchant_bonus_enabled', '1'),
(1, 'merchant_referral_enabled', '0'),
(1, 'merchant_referral_reward_type', 'percent'),
(1, 'merchant_referral_reward_value', '0');

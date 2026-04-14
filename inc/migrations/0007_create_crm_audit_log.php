<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0007_create_crm_audit_log',
	'title'    => 'Create crm_audit_log table and logs.view permission',
	'callback' => function () {
		global $wpdb;

		// ── Таблица аудит-лога ────────────────────────────────────────────────
		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_audit_log` (
			  `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			  `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  `event_code`   VARCHAR(128)     NOT NULL DEFAULT '' COMMENT 'Машинный код события, напр. user.login.success',
			  `category`     VARCHAR(64)      NOT NULL DEFAULT '' COMMENT 'auth|users|rates|settings|system|security',
			  `level`        ENUM('info','warning','error','security') NOT NULL DEFAULT 'info',
			  `user_id`      BIGINT UNSIGNED  NULL     COMMENT 'ID пользователя, совершившего действие',
			  `user_login`   VARCHAR(60)      NOT NULL DEFAULT '',
			  `target_type`  VARCHAR(64)      NOT NULL DEFAULT '' COMMENT 'Тип сущности: user|rate|settings|...',
			  `target_id`    BIGINT UNSIGNED  NULL     COMMENT 'ID целевой сущности',
			  `action`       VARCHAR(64)      NOT NULL DEFAULT '' COMMENT 'create|update|delete|login|logout|...',
			  `message`      TEXT             NOT NULL,
			  `context_json` LONGTEXT         NULL     COMMENT 'JSON с доп. данными',
			  `ip_address`   VARCHAR(45)      NOT NULL DEFAULT '',
			  `user_agent`   VARCHAR(512)     NOT NULL DEFAULT '',
			  `request_uri`  VARCHAR(1024)    NOT NULL DEFAULT '',
			  `method`       VARCHAR(10)      NOT NULL DEFAULT '',
			  `source`       VARCHAR(128)     NOT NULL DEFAULT '' COMMENT 'Источник события (страница/handler)',
			  `is_success`   TINYINT(1)       NOT NULL DEFAULT 1,
			  PRIMARY KEY (`id`),
			  KEY `idx_created_at`  (`created_at`),
			  KEY `idx_category`    (`category`),
			  KEY `idx_level`       (`level`),
			  KEY `idx_user_id`     (`user_id`),
			  KEY `idx_event_code`  (`event_code`(32)),
			  KEY `idx_target`      (`target_type`, `target_id`),
			  KEY `idx_is_success`  (`is_success`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		// ── Право доступа logs.view ───────────────────────────────────────────
		$wpdb->query( "
			INSERT IGNORE INTO `crm_permissions` (`code`, `module`, `action`, `name`)
			VALUES ('logs.view', 'logs', 'view', 'Просмотр журнала аудита')
		" );

		return [
			'summary'  => 'Table crm_audit_log created; logs.view permission added.',
			'messages' => [
				'Created table crm_audit_log with indexes.',
				'Inserted permission logs.view (ignored if already existed).',
			],
		];
	},
];

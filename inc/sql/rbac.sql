-- ============================================================
-- Malibu Exchange — CRM RBAC
-- ДОКУМЕНТАЦИЯ / REFERENCE ONLY
--
-- АВТОРИТЕТНЫЙ ИСТОЧНИК ПРАВ И РОЛЕЙ — inc/rbac.php:
--   crm_rbac_permissions()  — манифест всех прав
--   crm_rbac_role_grants()  — матрица ролей
--   crm_rbac_sync()         — синхронизация с БД
--
-- Этот файл НЕ запускается напрямую. Изменения вносятся через:
--   1. Правку функций в inc/rbac.php
--   2. Создание новой миграции, вызывающей crm_rbac_sync()
--
-- Схема таблиц ниже актуальна только для справки.
-- ============================================================

-- ─── Допустимые статусы пользователя ────────────────────────────────────────
--
--   active   — аккаунт активен, вход разрешён
--   blocked  — заблокирован оператором, вход запрещён
--   archived — деактивирован / удалён мягко, вход запрещён
--   pending  — ожидает активации (новый или не подтверждённый)
--
--  Тип колонки: ENUM — MySQL отклонит любое другое значение на уровне БД.
--  В PHP используй константы из inc/rbac.php:
--    CRM_STATUS_ACTIVE / CRM_STATUS_BLOCKED / CRM_STATUS_ARCHIVED / CRM_STATUS_PENDING
--
-- ────────────────────────────────────────────────────────────────────────────

-- ─── Таблицы ────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `crm_roles` (
  `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(64)      NOT NULL,
  `name`        VARCHAR(128)     NOT NULL,
  `description` TEXT             DEFAULT NULL,
  `is_system`   TINYINT(1)       NOT NULL DEFAULT 0,
  `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_permissions` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`   VARCHAR(128) NOT NULL,
  `module` VARCHAR(64)  NOT NULL,
  `action` VARCHAR(64)  NOT NULL,
  `name`   VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_role_permissions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id`       INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_perm` (`role_id`, `permission_id`),
  KEY `fk_rp_role` (`role_id`),
  KEY `fk_rp_perm` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_user_roles` (
  `id`          INT UNSIGNED        NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT(20) UNSIGNED NOT NULL,
  `role_id`     INT UNSIGNED        NOT NULL,
  `assigned_by` BIGINT(20) UNSIGNED DEFAULT NULL,
  `assigned_at` DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_role` (`user_id`, `role_id`),
  KEY `fk_ur_user` (`user_id`),
  KEY `fk_ur_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_user_accounts` (
  `id`                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           BIGINT(20) UNSIGNED NOT NULL,
  `status`            ENUM('active','blocked','archived','pending') NOT NULL DEFAULT 'active',
  `phone`             VARCHAR(32)         DEFAULT NULL,
  `telegram_username` VARCHAR(128)        DEFAULT NULL,
  `telegram_id`       BIGINT(20)          DEFAULT NULL,
  `department`        VARCHAR(128)        DEFAULT NULL,
  `position_title`    VARCHAR(128)        DEFAULT NULL,
  `note`              TEXT                DEFAULT NULL,
  `created_at`        DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_id` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Seed: Roles ────────────────────────────────────────────────────────────

INSERT IGNORE INTO `crm_roles` (`code`, `name`, `description`, `is_system`) VALUES
('owner',           'Owner',           'Владелец системы — полный доступ',                  1),
('admin',           'Admin',           'Администратор CRM',                                 1),
('senior_operator', 'Senior Operator', 'Старший оператор',                                  0),
('operator',        'Operator',        'Оператор',                                          0),
('cashier',         'Cashier',         'Кассир',                                            0),
('compliance',      'Compliance',      'Специалист по соответствию требованиям',            0),
('accountant',      'Accountant',      'Бухгалтер',                                         0),
('support',         'Support',         'Специалист поддержки',                              0),
('auditor',         'Auditor',         'Аудитор',                                           0);

-- ─── Seed: Permissions ──────────────────────────────────────────────────────

INSERT IGNORE INTO `crm_permissions` (`code`, `module`, `action`, `name`) VALUES
('dashboard.view',      'dashboard',    'view',         'Просмотр дашборда'),
('users.view',          'users',        'view',         'Просмотр пользователей'),
('users.create',        'users',        'create',       'Создание пользователей'),
('users.edit',          'users',        'edit',         'Редактирование пользователей'),
('users.block',         'users',        'block',        'Блокировка пользователей'),
('users.delete',        'users',        'delete',       'Удаление пользователей'),
('users.assign_roles',  'users',        'assign_roles', 'Назначение ролей'),
('roles.view',          'roles',        'view',         'Просмотр ролей'),
('roles.edit',          'roles',        'edit',         'Редактирование ролей'),
('applications.view',   'applications', 'view',         'Просмотр заявок'),
('applications.create', 'applications', 'create',       'Создание заявок'),
('applications.edit',   'applications', 'edit',         'Редактирование заявок'),
('applications.delete', 'applications', 'delete',       'Удаление заявок'),
('payments.view',       'payments',     'view',         'Просмотр платежей'),
('payments.confirm',    'payments',     'confirm',      'Подтверждение платежей'),
('payments.reject',     'payments',     'reject',       'Отклонение платежей'),
('rates.view',          'rates',        'view',         'Просмотр курсов'),
('rates.edit',          'rates',        'edit',         'Редактирование курсов'),
('kyc.view',            'kyc',          'view',         'Просмотр KYC'),
('kyc.review',          'kyc',          'review',       'Проверка KYC'),
('aml.view',            'aml',          'view',         'Просмотр AML'),
('aml.review',          'aml',          'review',       'Проверка AML'),
('reports.view',        'reports',      'view',         'Просмотр отчётов'),
('reports.export',      'reports',      'export',       'Экспорт отчётов'),
('audit.view',          'audit',        'view',         'Просмотр аудит-лога'),
('settings.view',       'settings',     'view',         'Просмотр настроек'),
('settings.edit',       'settings',     'edit',         'Редактирование настроек');

-- ─── Seed: Role → Permissions ────────────────────────────────────────────────

-- owner: все права
INSERT IGNORE INTO `crm_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `crm_roles` r, `crm_permissions` p WHERE r.code = 'owner';

-- admin: всё кроме audit
INSERT IGNORE INTO `crm_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `crm_roles` r, `crm_permissions` p
WHERE r.code = 'admin' AND p.code NOT IN ('audit.view');

-- senior_operator
INSERT IGNORE INTO `crm_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `crm_roles` r, `crm_permissions` p
WHERE r.code = 'senior_operator'
AND p.code IN (
    'dashboard.view',
    'users.view',
    'applications.view','applications.create','applications.edit',
    'payments.view','payments.confirm',
    'rates.view',
    'kyc.view',
    'aml.view',
    'reports.view'
);

-- operator
INSERT IGNORE INTO `crm_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `crm_roles` r, `crm_permissions` p
WHERE r.code = 'operator'
AND p.code IN (
    'dashboard.view',
    'applications.view','applications.create','applications.edit',
    'payments.view',
    'rates.view'
);

-- cashier
INSERT IGNORE INTO `crm_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `crm_roles` r, `crm_permissions` p
WHERE r.code = 'cashier'
AND p.code IN (
    'dashboard.view',
    'payments.view','payments.confirm',
    'rates.view'
);

-- compliance
INSERT IGNORE INTO `crm_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `crm_roles` r, `crm_permissions` p
WHERE r.code = 'compliance'
AND p.code IN (
    'dashboard.view',
    'applications.view',
    'kyc.view','kyc.review',
    'aml.view','aml.review',
    'reports.view','reports.export'
);

-- accountant
INSERT IGNORE INTO `crm_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `crm_roles` r, `crm_permissions` p
WHERE r.code = 'accountant'
AND p.code IN (
    'dashboard.view',
    'payments.view',
    'reports.view','reports.export'
);

-- support
INSERT IGNORE INTO `crm_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `crm_roles` r, `crm_permissions` p
WHERE r.code = 'support'
AND p.code IN (
    'dashboard.view',
    'users.view',
    'applications.view'
);

-- auditor
INSERT IGNORE INTO `crm_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `crm_roles` r, `crm_permissions` p
WHERE r.code = 'auditor'
AND p.code IN (
    'dashboard.view',
    'audit.view',
    'reports.view'
);

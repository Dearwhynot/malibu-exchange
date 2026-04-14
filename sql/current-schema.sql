-- phpMyAdmin SQL Dump
-- version 5.1.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Apr 14, 2026 at 06:28 AM
-- Server version: 10.4.31-MariaDB
-- PHP Version: 7.2.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Database: `malibuex_wpdb`
--

-- --------------------------------------------------------

--
-- Table structure for table `crm_audit_log`
--

CREATE TABLE `crm_audit_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `organization_id` int(10) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Организация, к которой относится событие',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `event_code` varchar(128) NOT NULL DEFAULT '' COMMENT 'Машинный код события, напр. user.login.success',
  `category` varchar(64) NOT NULL DEFAULT '' COMMENT 'auth|users|rates|settings|system|security',
  `level` enum('info','warning','error','security') NOT NULL DEFAULT 'info',
  `user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'ID пользователя, совершившего действие',
  `user_login` varchar(60) NOT NULL DEFAULT '',
  `target_type` varchar(64) NOT NULL DEFAULT '' COMMENT 'Тип сущности: user|rate|settings|...',
  `target_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'ID целевой сущности',
  `action` varchar(64) NOT NULL DEFAULT '' COMMENT 'create|update|delete|login|logout|...',
  `message` text NOT NULL,
  `context_json` longtext DEFAULT NULL COMMENT 'JSON с доп. данными',
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(512) NOT NULL DEFAULT '',
  `request_uri` varchar(1024) NOT NULL DEFAULT '',
  `method` varchar(10) NOT NULL DEFAULT '',
  `source` varchar(128) NOT NULL DEFAULT '' COMMENT 'Источник события (страница/handler)',
  `is_success` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_currencies`
--

CREATE TABLE `crm_currencies` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(10) NOT NULL,
  `name` varchar(64) NOT NULL,
  `symbol` varchar(8) NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_market_snapshots_usdt`
--

CREATE TABLE `crm_market_snapshots_usdt` (
  `id` int(10) UNSIGNED NOT NULL,
  `organization_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `source` enum('rapira','bitkub','binance_th') NOT NULL COMMENT 'Источник: rapira=USDT/RUB, bitkub=THB/USDT, binance_th=USDT/THB',
  `symbol` varchar(32) NOT NULL COMMENT 'Торговая пара, напр. USDT/RUB',
  `bid` decimal(18,8) DEFAULT NULL COMMENT 'Лучшая цена покупки',
  `ask` decimal(18,8) DEFAULT NULL COMMENT 'Лучшая цена продажи',
  `mid` decimal(18,8) DEFAULT NULL COMMENT 'Средняя (bid+ask)/2',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_pair_coefficients`
--

CREATE TABLE `crm_pair_coefficients` (
  `id` int(10) UNSIGNED NOT NULL,
  `pair_id` int(10) UNSIGNED NOT NULL,
  `provider` varchar(64) NOT NULL COMMENT 'Внешний источник, напр. ex24',
  `source_param` varchar(128) NOT NULL COMMENT 'Параметр источника, напр. phuket',
  `coefficient` decimal(10,4) NOT NULL DEFAULT 0.0500,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_permissions`
--

CREATE TABLE `crm_permissions` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(128) NOT NULL,
  `module` varchar(64) NOT NULL,
  `action` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_rate_history`
--

CREATE TABLE `crm_rate_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `organization_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `pair_id` int(10) UNSIGNED NOT NULL,
  `provider` varchar(64) NOT NULL COMMENT 'Источник данных, напр. ex24',
  `source_param` varchar(128) NOT NULL COMMENT 'Параметр источника, напр. phuket',
  `competitor_sberbank_buy` decimal(10,4) DEFAULT NULL,
  `competitor_tinkoff_buy` decimal(10,4) DEFAULT NULL,
  `our_sberbank_rate` decimal(10,4) DEFAULT NULL,
  `our_tinkoff_rate` decimal(10,4) DEFAULT NULL,
  `coefficient_value` decimal(10,4) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_rate_pairs`
--

CREATE TABLE `crm_rate_pairs` (
  `id` int(10) UNSIGNED NOT NULL,
  `organization_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `from_currency_id` int(10) UNSIGNED NOT NULL,
  `to_currency_id` int(10) UNSIGNED NOT NULL,
  `code` varchar(32) NOT NULL,
  `title` varchar(128) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_roles`
--

CREATE TABLE `crm_roles` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `description` text DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_role_permissions`
--

CREATE TABLE `crm_role_permissions` (
  `id` int(10) UNSIGNED NOT NULL,
  `role_id` int(10) UNSIGNED NOT NULL,
  `permission_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_settings`
--

CREATE TABLE `crm_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `org_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `setting_key` varchar(128) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_user_accounts`
--

CREATE TABLE `crm_user_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('active','blocked','archived','pending') NOT NULL DEFAULT 'active',
  `phone` varchar(32) DEFAULT NULL,
  `telegram_username` varchar(128) DEFAULT NULL,
  `telegram_id` bigint(20) DEFAULT NULL,
  `department` varchar(128) DEFAULT NULL,
  `position_title` varchar(128) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_user_last_login`
--

CREATE TABLE `crm_user_last_login` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `login_at` datetime NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_user_roles`
--

CREATE TABLE `crm_user_roles` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `role_id` int(10) UNSIGNED NOT NULL,
  `assigned_by` bigint(20) UNSIGNED DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `crm_audit_log`
--
ALTER TABLE `crm_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_level` (`level`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_event_code` (`event_code`(32)),
  ADD KEY `idx_target` (`target_type`,`target_id`),
  ADD KEY `idx_is_success` (`is_success`),
  ADD KEY `idx_org_id` (`organization_id`);

--
-- Indexes for table `crm_currencies`
--
ALTER TABLE `crm_currencies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_code` (`code`);

--
-- Indexes for table `crm_market_snapshots_usdt`
--
ALTER TABLE `crm_market_snapshots_usdt`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_source_created` (`source`,`created_at`),
  ADD KEY `idx_org_source` (`organization_id`,`source`);

--
-- Indexes for table `crm_pair_coefficients`
--
ALTER TABLE `crm_pair_coefficients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pair_provider_source` (`pair_id`,`provider`,`source_param`);

--
-- Indexes for table `crm_permissions`
--
ALTER TABLE `crm_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_code` (`code`);

--
-- Indexes for table `crm_rate_history`
--
ALTER TABLE `crm_rate_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pair_created` (`pair_id`,`created_at`),
  ADD KEY `idx_org_pair` (`organization_id`,`pair_id`);

--
-- Indexes for table `crm_rate_pairs`
--
ALTER TABLE `crm_rate_pairs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_org_pair` (`organization_id`,`from_currency_id`,`to_currency_id`),
  ADD KEY `idx_org_active` (`organization_id`,`is_active`);

--
-- Indexes for table `crm_roles`
--
ALTER TABLE `crm_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_code` (`code`);

--
-- Indexes for table `crm_role_permissions`
--
ALTER TABLE `crm_role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_role_perm` (`role_id`,`permission_id`),
  ADD KEY `fk_rp_role` (`role_id`),
  ADD KEY `fk_rp_perm` (`permission_id`);

--
-- Indexes for table `crm_settings`
--
ALTER TABLE `crm_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_org_key` (`org_id`,`setting_key`);

--
-- Indexes for table `crm_user_accounts`
--
ALTER TABLE `crm_user_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_id` (`user_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `crm_user_last_login`
--
ALTER TABLE `crm_user_last_login`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_login_at` (`login_at`);

--
-- Indexes for table `crm_user_roles`
--
ALTER TABLE `crm_user_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_role` (`user_id`,`role_id`),
  ADD KEY `fk_ur_user` (`user_id`),
  ADD KEY `fk_ur_role` (`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `crm_audit_log`
--
ALTER TABLE `crm_audit_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_currencies`
--
ALTER TABLE `crm_currencies`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_market_snapshots_usdt`
--
ALTER TABLE `crm_market_snapshots_usdt`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_pair_coefficients`
--
ALTER TABLE `crm_pair_coefficients`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_permissions`
--
ALTER TABLE `crm_permissions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_rate_history`
--
ALTER TABLE `crm_rate_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_rate_pairs`
--
ALTER TABLE `crm_rate_pairs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_roles`
--
ALTER TABLE `crm_roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_role_permissions`
--
ALTER TABLE `crm_role_permissions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_settings`
--
ALTER TABLE `crm_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_user_accounts`
--
ALTER TABLE `crm_user_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_user_last_login`
--
ALTER TABLE `crm_user_last_login`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `crm_user_roles`
--
ALTER TABLE `crm_user_roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;
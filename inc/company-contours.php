<?php
/**
 * Malibu Exchange — Unified company contours helper
 *
 * This layer does not replace existing storage. It normalizes reads/writes for:
 * - exchange directions via crm_rate_pairs;
 * - fintech providers via fintech_allowed_providers;
 * - telegram contexts via existing telegram settings architecture;
 * - merchant feature flags via crm_settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'crm_normalize_legacy_pair_code' ) ) {
	function crm_normalize_legacy_pair_code( string $pair_code ): string {
		$pair_code = strtoupper( trim( $pair_code ) );

		if ( $pair_code === 'THB_RUB' ) {
			return 'RUB_THB';
		}

		return $pair_code;
	}
}

if ( ! function_exists( 'crm_company_contours_registry' ) ) {
	function crm_company_contours_registry(): array {
		static $registry = null;

		if ( is_array( $registry ) ) {
			return $registry;
		}

		$fintech_labels = function_exists( 'crm_fintech_provider_labels' )
			? crm_fintech_provider_labels()
			: [
				'kanyon'  => 'Kanyon (Pay2Day)',
				'doverka' => 'Doverka',
			];

		$telegram_labels = function_exists( 'crm_telegram_bot_context_labels' )
			? crm_telegram_bot_context_labels()
			: [
				'merchant' => 'Мерчантский бот',
				'operator' => 'Операторский бот',
				'service'  => 'Сервисный бот',
			];

		$registry = [
			'exchange_pairs'    => [
				'RUB_THB'  => [
					'group'                  => 'exchange_pairs',
					'code'                   => 'RUB_THB',
					'legacy_codes'           => [ 'THB_RUB' ],
					'title'                  => 'RUB/THB',
					'label'                  => 'RUB -> THB',
					'hint'                   => 'Основное направление обмена RUB/THB с котировкой Ex24 Phuket.',
					'from_code'              => 'THB',
					'to_code'                => 'RUB',
					'default_coefficient'    => 0.05,
					'sort_order'             => 10,
					'supports_market_source' => false,
				],
				'USDT_THB' => [
					'group'                  => 'exchange_pairs',
					'code'                   => 'USDT_THB',
					'title'                  => 'USDT/THB',
					'label'                  => 'USDT -> THB',
					'hint'                   => 'USDT/THB направление с выбором рыночного источника в настройках компании.',
					'from_code'              => 'USDT',
					'to_code'                => 'THB',
					'default_coefficient'    => 0.05,
					'sort_order'             => 20,
					'supports_market_source' => true,
					'default_market_source'  => 'bitkub',
				],
				'RUB_USDT' => [
					'group'                  => 'exchange_pairs',
					'code'                   => 'RUB_USDT',
					'title'                  => 'USDT/RUB',
					'label'                  => 'RUB -> USDT',
					'hint'                   => 'RUB/USDT направление для связанных расчётов и payout/fintech сценариев.',
					'from_code'              => 'RUB',
					'to_code'                => 'USDT',
					'default_coefficient'    => 0.05,
					'sort_order'             => 30,
					'supports_market_source' => false,
				],
			],
			'fintech_providers' => [
				'kanyon'  => [
					'group' => 'fintech_providers',
					'code'  => 'kanyon',
					'title' => (string) ( $fintech_labels['kanyon'] ?? 'Kanyon (Pay2Day)' ),
					'label' => 'Kanyon (Pay2Day)',
					'hint'  => 'Логин, пароль и создание новых ордеров через Kanyon / Pay2Day.',
				],
				'doverka' => [
					'group' => 'fintech_providers',
					'code'  => 'doverka',
					'title' => (string) ( $fintech_labels['doverka'] ?? 'Doverka' ),
					'label' => 'Doverka',
					'hint'  => 'API-ключ Doverka, выбор активного провайдера и создание новых ордеров.',
				],
			],
			'telegram_contexts' => [
				'merchant' => [
					'group' => 'telegram_contexts',
					'code'  => 'merchant',
					'title' => (string) ( $telegram_labels['merchant'] ?? 'Мерчантский бот' ),
					'label' => 'Мерчантский бот',
					'hint'  => 'Основной merchant bot компании.',
				],
				'operator' => [
					'group' => 'telegram_contexts',
					'code'  => 'operator',
					'title' => (string) ( $telegram_labels['operator'] ?? 'Операторский бот' ),
					'label' => 'Операторский бот',
					'hint'  => 'Внутренний Telegram-контур операторов.',
				],
				'service'  => [
					'group' => 'telegram_contexts',
					'code'  => 'service',
					'title' => (string) ( $telegram_labels['service'] ?? 'Сервисный бот' ),
					'label' => 'Сервисный бот',
					'hint'  => 'Отдельный Telegram-контур для сервисных сценариев компании.',
				],
			],
			'merchant_features' => [
				'bonus'    => [
					'group'           => 'merchant_features',
					'code'            => 'bonus',
					'title'           => 'Бонусный контур',
					'label'           => 'Бонусы',
					'hint'            => 'Начисление бонусов мерчантам.',
					'setting_key'     => 'merchant_bonus_enabled',
					'default_enabled' => true,
				],
				'referral' => [
					'group'           => 'merchant_features',
					'code'            => 'referral',
					'title'           => 'Реферальный контур',
					'label'           => 'Рефералка',
					'hint'            => 'Реферальные начисления для merchant-контура.',
					'setting_key'     => 'merchant_referral_enabled',
					'default_enabled' => false,
				],
			],
		];

		return $registry;
	}
}

if ( ! function_exists( 'crm_company_exchange_pair_definitions' ) ) {
	function crm_company_exchange_pair_definitions(): array {
		return array_values( crm_company_contours_registry()['exchange_pairs'] ?? [] );
	}
}

if ( ! function_exists( 'crm_company_fintech_provider_definitions' ) ) {
	function crm_company_fintech_provider_definitions(): array {
		return array_values( crm_company_contours_registry()['fintech_providers'] ?? [] );
	}
}

if ( ! function_exists( 'crm_company_contour_get' ) ) {
	function crm_company_contour_get( string $code ): ?array {
		$code     = trim( $code );
		$registry = crm_company_contours_registry();

		if ( $code === '' ) {
			return null;
		}

		$pair_code = crm_normalize_legacy_pair_code( $code );
		if ( isset( $registry['exchange_pairs'][ $pair_code ] ) ) {
			return $registry['exchange_pairs'][ $pair_code ];
		}

		$code = sanitize_key( $code );

		foreach ( [ 'fintech_providers', 'telegram_contexts', 'merchant_features' ] as $group ) {
			if ( isset( $registry[ $group ][ $code ] ) ) {
				return $registry[ $group ][ $code ];
			}
		}

		return null;
	}
}

if ( ! function_exists( 'crm_company_contour_exists' ) ) {
	function crm_company_contour_exists( string $code ): bool {
		return is_array( crm_company_contour_get( $code ) );
	}
}

if ( ! function_exists( 'crm_root_available_rate_pairs' ) ) {
	function crm_root_available_rate_pairs(): array {
		return crm_company_exchange_pair_definitions();
	}
}

if ( ! function_exists( 'crm_root_get_pair_definition' ) ) {
	function crm_root_get_pair_definition( string $code ): ?array {
		$contour = crm_company_contour_get( $code );

		if ( ! is_array( $contour ) || ( $contour['group'] ?? '' ) !== 'exchange_pairs' ) {
			return null;
		}

		return $contour;
	}
}

if ( ! function_exists( 'crm_company_pair_supports_market_source' ) ) {
	function crm_company_pair_supports_market_source( string $pair_code ): bool {
		$pair = crm_root_get_pair_definition( $pair_code );

		return ! empty( $pair['supports_market_source'] );
	}
}

if ( ! function_exists( 'crm_company_exchange_pair_lookup_codes' ) ) {
	function crm_company_exchange_pair_lookup_codes( string $pair_code ): array {
		$pair = crm_root_get_pair_definition( $pair_code );

		if ( ! $pair ) {
			return [];
		}

		$codes = [ (string) $pair['code'] ];

		foreach ( (array) ( $pair['legacy_codes'] ?? [] ) as $legacy_code ) {
			$legacy_code = strtoupper( trim( (string) $legacy_code ) );
			if ( $legacy_code !== '' ) {
				$codes[] = $legacy_code;
			}
		}

		return array_values( array_unique( $codes ) );
	}
}

if ( ! function_exists( 'crm_company_get_rate_pair_row' ) ) {
	function crm_company_get_rate_pair_row( int $company_id, string $pair_code, bool $active_only = false ): ?object {
		global $wpdb;

		if ( $company_id <= 0 ) {
			return null;
		}

		$lookup_codes = crm_company_exchange_pair_lookup_codes( $pair_code );
		if ( empty( $lookup_codes ) ) {
			return null;
		}

		$sql = sprintf(
			'SELECT * FROM crm_rate_pairs WHERE organization_id = %%d AND code IN (%s)',
			implode( ', ', array_fill( 0, count( $lookup_codes ), '%s' ) )
		);

		if ( $active_only ) {
			$sql .= ' AND is_active = 1';
		}

		$sql .= ' ORDER BY updated_at DESC, id DESC';

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, array_merge( [ $company_id ], $lookup_codes ) )
		);

		if ( empty( $rows ) ) {
			return null;
		}

		$canonical_code = crm_normalize_legacy_pair_code( $pair_code );

		foreach ( $rows as $row ) {
			if ( crm_normalize_legacy_pair_code( (string) $row->code ) === $canonical_code ) {
				return $row;
			}
		}

		return $rows[0];
	}
}

if ( ! function_exists( 'crm_company_contours_parse_json_allowlist' ) ) {
	function crm_company_contours_parse_json_allowlist( $raw_value ): array {
		if ( is_array( $raw_value ) ) {
			$items = $raw_value;
		} else {
			$raw_value = trim( (string) $raw_value );

			if ( $raw_value === '' ) {
				return [];
			}

			$decoded = json_decode( $raw_value, true );
			if ( is_array( $decoded ) ) {
				$items = $decoded;
			} else {
				$items = preg_split( '/\s*,\s*/', $raw_value, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
			}
		}

		$normalized = [];
		foreach ( $items as $item ) {
			$item = sanitize_key( (string) $item );
			if ( $item !== '' ) {
				$normalized[ $item ] = $item;
			}
		}

		return array_values( $normalized );
	}
}

if ( ! function_exists( 'crm_company_contours_bool_setting_is_enabled' ) ) {
	function crm_company_contours_bool_setting_is_enabled( int $company_id, string $setting_key, bool $default_enabled ): bool {
		$default = $default_enabled ? '1' : '0';
		return crm_get_setting( $setting_key, $company_id, $default ) === '1';
	}
}

if ( ! function_exists( 'crm_company_get_enabled_exchange_pairs' ) ) {
	function crm_company_get_enabled_exchange_pairs( int $company_id ): array {
		global $wpdb;

		if ( $company_id <= 0 ) {
			return [];
		}

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT code FROM crm_rate_pairs WHERE organization_id = %d AND is_active = 1 ORDER BY sort_order ASC, id ASC',
				$company_id
			)
		);

		$enabled_map = [];
		foreach ( $rows as $code ) {
			$pair = crm_root_get_pair_definition( (string) $code );
			if ( $pair ) {
				$enabled_map[ $pair['code'] ] = $pair['code'];
			}
		}

		$enabled = [];
		foreach ( crm_company_exchange_pair_definitions() as $pair ) {
			if ( isset( $enabled_map[ $pair['code'] ] ) ) {
				$enabled[] = $pair['code'];
			}
		}

		return $enabled;
	}
}

if ( ! function_exists( 'crm_company_get_enabled_fintech_providers' ) ) {
	function crm_company_get_enabled_fintech_providers( int $company_id ): array {
		$registry = crm_company_contours_registry()['fintech_providers'] ?? [];
		$raw      = crm_get_setting( 'fintech_allowed_providers', $company_id, '' );
		$raw_text = trim( (string) $raw );
		$parsed   = crm_company_contours_parse_json_allowlist( $raw );

		if ( $raw_text === '' ) {
			$parsed = array_keys( $registry );
		}

		$enabled = [];
		foreach ( array_keys( $registry ) as $provider_code ) {
			if ( in_array( $provider_code, $parsed, true ) ) {
				$enabled[] = $provider_code;
			}
		}

		return $enabled;
	}
}

if ( ! function_exists( 'crm_company_get_enabled_telegram_contexts' ) ) {
	function crm_company_get_enabled_telegram_contexts( int $company_id ): array {
		if ( $company_id <= 0 ) {
			return [];
		}

		return array_keys( crm_company_contours_registry()['telegram_contexts'] ?? [] );
	}
}

if ( ! function_exists( 'crm_company_rub_usdt_fixation_mode_definitions' ) ) {
	function crm_company_rub_usdt_fixation_mode_definitions(): array {
		return [
			'rapira_manual'    => [
				'code'  => 'rapira_manual',
				'label' => 'Через Rapira',
				'hint'  => 'Курс USDT/RUB фиксируется вручную из блока Rapira кнопкой «Сохранить».',
			],
			'telegram_service' => [
				'code'  => 'telegram_service',
				'label' => 'Через Telegram',
				'hint'  => 'Базовый курс USDT/RUB приходит извне и должен фиксироваться сервисным Telegram-ботом, а не вручную.',
			],
		];
	}
}

if ( ! function_exists( 'crm_company_normalize_rub_usdt_fixation_mode' ) ) {
	function crm_company_normalize_rub_usdt_fixation_mode( string $mode ): string {
		$mode        = sanitize_key( $mode );
		$definitions = crm_company_rub_usdt_fixation_mode_definitions();

		return isset( $definitions[ $mode ] ) ? $mode : 'rapira_manual';
	}
}

if ( ! function_exists( 'crm_company_get_rub_usdt_fixation_mode' ) ) {
	function crm_company_get_rub_usdt_fixation_mode( int $company_id ): string {
		if ( $company_id <= 0 ) {
			return 'rapira_manual';
		}

		$raw_mode = (string) crm_get_setting( 'rub_usdt_fixation_mode', $company_id, 'rapira_manual' );

		return crm_company_normalize_rub_usdt_fixation_mode( $raw_mode );
	}
}

if ( ! function_exists( 'crm_company_get_rub_usdt_fixation_mode_label' ) ) {
	function crm_company_get_rub_usdt_fixation_mode_label( string $mode ): string {
		$mode        = crm_company_normalize_rub_usdt_fixation_mode( $mode );
		$definitions = crm_company_rub_usdt_fixation_mode_definitions();

		return (string) ( $definitions[ $mode ]['label'] ?? $mode );
	}
}

if ( ! function_exists( 'crm_company_set_rub_usdt_fixation_mode' ) ) {
	function crm_company_set_rub_usdt_fixation_mode( int $company_id, string $mode ): bool {
		if ( $company_id <= 0 ) {
			return false;
		}

		return crm_set_setting(
			'rub_usdt_fixation_mode',
			crm_company_normalize_rub_usdt_fixation_mode( $mode ),
			$company_id
		);
	}
}

if ( ! function_exists( 'crm_company_seed_rub_usdt_fixation_settings' ) ) {
	function crm_company_seed_rub_usdt_fixation_settings( int $company_id ): void {
		if ( $company_id <= 0 ) {
			return;
		}

		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO crm_settings (org_id, setting_key, setting_value) VALUES (%d, %s, %s)',
				$company_id,
				'rub_usdt_fixation_mode',
				'rapira_manual'
			)
		);
	}
}

if ( ! function_exists( 'crm_company_get_enabled_merchant_features' ) ) {
	function crm_company_get_enabled_merchant_features( int $company_id ): array {
		if ( $company_id <= 0 ) {
			return [];
		}

		$enabled = [];
		foreach ( crm_company_contours_registry()['merchant_features'] ?? [] as $code => $feature ) {
			$setting_key      = (string) ( $feature['setting_key'] ?? '' );
			$default_enabled  = ! empty( $feature['default_enabled'] );
			if ( $setting_key !== '' && crm_company_contours_bool_setting_is_enabled( $company_id, $setting_key, $default_enabled ) ) {
				$enabled[] = $code;
			}
		}

		return $enabled;
	}
}

if ( ! function_exists( 'crm_company_get_enabled_contours_by_group' ) ) {
	function crm_company_get_enabled_contours_by_group( int $company_id, string $group ): array {
		switch ( $group ) {
			case 'exchange_pairs':
				return crm_company_get_enabled_exchange_pairs( $company_id );
			case 'fintech_providers':
				return crm_company_get_enabled_fintech_providers( $company_id );
			case 'telegram_contexts':
				return crm_company_get_enabled_telegram_contexts( $company_id );
			case 'merchant_features':
				return crm_company_get_enabled_merchant_features( $company_id );
			default:
				return [];
		}
	}
}

if ( ! function_exists( 'crm_company_contour_is_enabled' ) ) {
	function crm_company_contour_is_enabled( int $company_id, string $code ): bool {
		$contour = crm_company_contour_get( $code );

		if ( ! is_array( $contour ) ) {
			return false;
		}

		return in_array(
			(string) $contour['code'],
			crm_company_get_enabled_contours_by_group( $company_id, (string) $contour['group'] ),
			true
		);
	}
}

if ( ! function_exists( 'crm_company_get_enabled_invoice_directions' ) ) {
	function crm_company_get_enabled_invoice_directions( int $company_id ): array {
		return crm_company_get_enabled_exchange_pairs( $company_id );
	}
}

if ( ! function_exists( 'crm_company_has_any_exchange_direction' ) ) {
	function crm_company_has_any_exchange_direction( int $company_id ): bool {
		return ! empty( crm_company_get_enabled_exchange_pairs( $company_id ) );
	}
}

if ( ! function_exists( 'crm_company_set_exchange_pair_enabled' ) ) {
	function crm_company_set_exchange_pair_enabled( int $company_id, string $pair_code, bool $enabled ) {
		global $wpdb;

		if ( $company_id <= 0 ) {
			return new WP_Error( 'invalid_company', 'Некорректный company_id для направления обмена.' );
		}

		$pair = crm_root_get_pair_definition( $pair_code );
		if ( ! $pair ) {
			return new WP_Error( 'unknown_pair', 'Неизвестное направление обмена: ' . $pair_code );
		}

		$company = function_exists( 'crm_get_company_by_id' ) ? crm_get_company_by_id( $company_id ) : null;
		if ( ! $company ) {
			return new WP_Error( 'company_not_found', 'Компания не найдена.' );
		}

		$existing_row = crm_company_get_rate_pair_row( $company_id, $pair['code'], false );
		$pair_id      = $existing_row ? (int) $existing_row->id : 0;
		$was_enabled  = $existing_row ? ( (int) $existing_row->is_active === 1 ) : false;
		$created      = false;

		if ( ! $enabled && ! $existing_row ) {
			return [
				'group'          => 'exchange_pairs',
				'code'           => (string) $pair['code'],
				'enabled'        => false,
				'changed'        => false,
				'created'        => false,
				'pair_id'        => 0,
				'previous_state' => false,
			];
		}

		$from_id = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM crm_currencies WHERE code = %s', (string) $pair['from_code'] )
		);
		$to_id = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM crm_currencies WHERE code = %s', (string) $pair['to_code'] )
		);

		if ( $from_id <= 0 || $to_id <= 0 ) {
			return new WP_Error(
				'missing_currency',
				sprintf( 'Справочник валют не содержит %s/%s.', $pair['from_code'], $pair['to_code'] )
			);
		}

		$pair_row = [
			'organization_id'  => $company_id,
			'from_currency_id' => $from_id,
			'to_currency_id'   => $to_id,
			'code'             => (string) $pair['code'],
			'title'            => (string) $pair['title'],
			'is_active'        => $enabled ? 1 : 0,
			'sort_order'       => (int) ( $pair['sort_order'] ?? 0 ),
		];

		$formats = [ '%d', '%d', '%d', '%s', '%s', '%d', '%d' ];

		if ( ! $existing_row && ! empty( $pair['supports_market_source'] ) ) {
			$pair_row['market_source'] = (string) ( $pair['default_market_source'] ?? 'bitkub' );
			$formats[]                 = '%s';
		}

		if ( $existing_row ) {
			$updated = $wpdb->update(
				'crm_rate_pairs',
				$pair_row,
				[ 'id' => $pair_id ],
				$formats,
				[ '%d' ]
			);

			if ( $updated === false ) {
				return new WP_Error( 'pair_update_failed', 'Не удалось обновить направление обмена.' );
			}
		} else {
			$created = true;
			$inserted = $wpdb->insert( 'crm_rate_pairs', $pair_row, $formats );

			if ( $inserted === false ) {
				return new WP_Error( 'pair_insert_failed', 'Не удалось создать направление обмена.' );
			}

			$pair_id = (int) $wpdb->insert_id;
		}

		if ( $enabled && $pair_id > 0 ) {
			$coeff_exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM crm_pair_coefficients WHERE pair_id = %d AND provider = %s AND source_param = %s LIMIT 1',
					$pair_id,
					'ex24',
					'phuket'
				)
			);

			if ( $coeff_exists <= 0 ) {
				$wpdb->insert(
					'crm_pair_coefficients',
					[
						'pair_id'          => $pair_id,
						'provider'         => 'ex24',
						'source_param'     => 'phuket',
						'coefficient'      => 0.0,
						'coefficient_type' => 'absolute',
					],
					[ '%d', '%s', '%s', '%f', '%s' ]
				);
			}
		}

		return [
			'group'          => 'exchange_pairs',
			'code'           => (string) $pair['code'],
			'enabled'        => $enabled,
			'changed'        => $was_enabled !== $enabled || $created,
			'created'        => $created,
			'pair_id'        => $pair_id,
			'previous_state' => $was_enabled,
		];
	}
}

if ( ! function_exists( 'crm_company_set_fintech_provider_enabled' ) ) {
	function crm_company_set_fintech_provider_enabled( int $company_id, string $provider_code, bool $enabled ) {
		if ( $company_id <= 0 ) {
			return new WP_Error( 'invalid_company', 'Некорректный company_id для платёжного контура.' );
		}

		$provider = crm_company_contour_get( $provider_code );
		if ( ! is_array( $provider ) || ( $provider['group'] ?? '' ) !== 'fintech_providers' ) {
			return new WP_Error( 'unknown_provider', 'Неизвестный платёжный контур: ' . $provider_code );
		}

		$current = crm_company_get_enabled_fintech_providers( $company_id );
		$before  = in_array( (string) $provider['code'], $current, true );
		$next    = array_fill_keys( $current, true );

		if ( $enabled ) {
			$next[ $provider['code'] ] = $provider['code'];
		} else {
			unset( $next[ $provider['code'] ] );
		}

		$ordered = [];
		foreach ( crm_company_fintech_provider_definitions() as $provider_def ) {
			if ( isset( $next[ $provider_def['code'] ] ) ) {
				$ordered[] = $provider_def['code'];
			}
		}

		crm_set_setting(
			'fintech_allowed_providers',
			(string) wp_json_encode( $ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			$company_id
		);

		$current_active_provider = sanitize_key( (string) crm_get_setting( 'fintech_active_provider', $company_id, '' ) );
		$active_provider_cleared = false;

		if ( $current_active_provider !== '' && ! in_array( $current_active_provider, $ordered, true ) ) {
			crm_set_setting( 'fintech_active_provider', '', $company_id );
			$active_provider_cleared = true;
		}

		return [
			'group'                   => 'fintech_providers',
			'code'                    => (string) $provider['code'],
			'enabled'                 => $enabled,
			'changed'                 => $before !== $enabled,
			'previous_state'          => $before,
			'allowed_providers'       => $ordered,
			'active_provider_cleared' => $active_provider_cleared,
		];
	}
}

if ( ! function_exists( 'crm_company_set_merchant_feature_enabled' ) ) {
	function crm_company_set_merchant_feature_enabled( int $company_id, string $feature_code, bool $enabled ) {
		if ( $company_id <= 0 ) {
			return new WP_Error( 'invalid_company', 'Некорректный company_id для merchant-контура.' );
		}

		$feature = crm_company_contour_get( $feature_code );
		if ( ! is_array( $feature ) || ( $feature['group'] ?? '' ) !== 'merchant_features' ) {
			return new WP_Error( 'unknown_feature', 'Неизвестный merchant-контур: ' . $feature_code );
		}

		$setting_key = (string) ( $feature['setting_key'] ?? '' );
		if ( $setting_key === '' ) {
			return new WP_Error( 'feature_setting_missing', 'Для merchant-контура не указан setting_key.' );
		}

		$before = crm_company_contours_bool_setting_is_enabled(
			$company_id,
			$setting_key,
			! empty( $feature['default_enabled'] )
		);

		crm_set_setting( $setting_key, $enabled ? '1' : '0', $company_id );

		return [
			'group'          => 'merchant_features',
			'code'           => (string) $feature['code'],
			'enabled'        => $enabled,
			'changed'        => $before !== $enabled,
			'previous_state' => $before,
		];
	}
}

if ( ! function_exists( 'crm_company_replace_enabled_contours' ) ) {
	function crm_company_replace_enabled_contours( int $company_id, string $group, array $enabled_codes ) {
		$registry = crm_company_contours_registry();
		if ( ! isset( $registry[ $group ] ) ) {
			return new WP_Error( 'unknown_group', 'Неизвестная группа контуров: ' . $group );
		}

		$current_codes = crm_company_get_enabled_contours_by_group( $company_id, $group );
		$desired_map   = [];

		foreach ( $enabled_codes as $enabled_code ) {
			$contour = crm_company_contour_get( (string) $enabled_code );
			if ( is_array( $contour ) && ( $contour['group'] ?? '' ) === $group ) {
				$desired_map[ $contour['code'] ] = $contour['code'];
			}
		}

		$changes = [];

		foreach ( $registry[ $group ] as $code => $contour ) {
			$should_enable = isset( $desired_map[ $code ] );
			$is_enabled    = in_array( $code, $current_codes, true );

			if ( $should_enable === $is_enabled ) {
				continue;
			}

			switch ( $group ) {
				case 'exchange_pairs':
					$result = crm_company_set_exchange_pair_enabled( $company_id, $code, $should_enable );
					break;
				case 'fintech_providers':
					$result = crm_company_set_fintech_provider_enabled( $company_id, $code, $should_enable );
					break;
				case 'merchant_features':
					$result = crm_company_set_merchant_feature_enabled( $company_id, $code, $should_enable );
					break;
				case 'telegram_contexts':
					return new WP_Error( 'telegram_write_not_supported', 'Запись telegram-контуров на этом этапе ещё не реализована.' );
				default:
					return new WP_Error( 'unknown_group', 'Неизвестная группа контуров: ' . $group );
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$changes[ $code ] = $result;
		}

		return [
			'group'         => $group,
			'enabled_codes' => crm_company_get_enabled_contours_by_group( $company_id, $group ),
			'changes'       => $changes,
		];
	}
}

if ( ! function_exists( 'crm_company_render_exchange_pair_badges_html' ) ) {
	function crm_company_render_exchange_pair_badges_html( array $pair_codes ): string {
		$enabled_map = [];

		foreach ( $pair_codes as $pair_code ) {
			$pair = crm_root_get_pair_definition( (string) $pair_code );
			if ( $pair ) {
				$enabled_map[ $pair['code'] ] = $pair;
			}
		}

		ob_start();
		if ( empty( $enabled_map ) ) :
			?>
			<span class="badge badge-secondary m-r-2">Направления выключены</span>
			<?php
		else :
			foreach ( crm_company_exchange_pair_definitions() as $pair ) :
				if ( ! isset( $enabled_map[ $pair['code'] ] ) ) {
					continue;
				}
				?>
				<span class="badge badge-success m-r-2"><?php echo esc_html( $pair['title'] ); ?></span>
				<?php
			endforeach;
		endif;

		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'crm_company_render_fintech_provider_badges_html' ) ) {
	function crm_company_render_fintech_provider_badges_html( array $provider_codes ): string {
		$enabled_map = array_fill_keys( crm_company_get_enabled_fintech_providers_from_array( $provider_codes ), true );

		ob_start();
		if ( empty( $enabled_map ) ) :
			?>
			<span class="badge badge-secondary m-r-2">Платёжные контуры отключены</span>
			<?php
		else :
			foreach ( crm_company_fintech_provider_definitions() as $provider ) :
				if ( ! isset( $enabled_map[ $provider['code'] ] ) ) {
					continue;
				}
				$badge_class = $provider['code'] === 'doverka' ? 'badge-info' : 'badge-primary';
				?>
				<span class="badge <?php echo esc_attr( $badge_class ); ?> m-r-2"><?php echo esc_html( $provider['title'] ); ?></span>
				<?php
			endforeach;
		endif;

		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'crm_company_get_enabled_fintech_providers_from_array' ) ) {
	function crm_company_get_enabled_fintech_providers_from_array( array $provider_codes ): array {
		$registry = crm_company_contours_registry()['fintech_providers'] ?? [];
		$parsed   = crm_company_contours_parse_json_allowlist( $provider_codes );
		$enabled  = [];

		foreach ( array_keys( $registry ) as $provider_code ) {
			if ( in_array( $provider_code, $parsed, true ) ) {
				$enabled[] = $provider_code;
			}
		}

		return $enabled;
	}
}

<?php
/**
 * Malibu Exchange — Merchants Module
 *
 * Мерчант — отдельная business-сущность внутри компании.
 * На первом этапе мерчанты НЕ являются WP users и НЕ входят в WP-админку.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CRM_MERCHANT_STATUS_ACTIVE',   'active' );
define( 'CRM_MERCHANT_STATUS_BLOCKED',  'blocked' );
define( 'CRM_MERCHANT_STATUS_ARCHIVED', 'archived' );
define( 'CRM_MERCHANT_STATUS_PENDING',  'pending' );
define( 'CRM_MERCHANT_WALLET_ENTRY_MERCHANT_ACCRUAL', 'merchant_accrual' );
define( 'CRM_MERCHANT_WALLET_ENTRY_MERCHANT_PAYOUT', 'merchant_payout' );
define( 'CRM_MERCHANT_WALLET_ENTRY_MERCHANT_PAYOUT_REVERSAL', 'merchant_payout_reversal' );
define( 'CRM_MERCHANT_PAYOUT_STATUS_PAID', 'paid' );
define( 'CRM_MERCHANT_PAYOUT_STATUS_CONFIRMED', 'confirmed' );
define( 'CRM_MERCHANT_PAYOUT_STATUS_CANCELLED', 'cancelled' );

/**
 * Справочник статусов мерчанта.
 *
 * @return array<string,string>
 */
function crm_merchant_statuses(): array {
	return [
		CRM_MERCHANT_STATUS_ACTIVE   => 'Активен',
		CRM_MERCHANT_STATUS_BLOCKED  => 'Заблокирован',
		CRM_MERCHANT_STATUS_ARCHIVED => 'Архив',
		CRM_MERCHANT_STATUS_PENDING  => 'Ожидает активации',
	];
}

/**
 * Справочник статусов выплаты мерчанту.
 *
 * @return array<string,string>
 */
function crm_merchant_payout_statuses(): array {
	return [
		CRM_MERCHANT_PAYOUT_STATUS_PAID      => 'Выплата произведена',
		CRM_MERCHANT_PAYOUT_STATUS_CONFIRMED => 'Подтверждён',
		CRM_MERCHANT_PAYOUT_STATUS_CANCELLED => 'Отменён',
	];
}

/**
 * Нормализует статус выплаты мерчанту.
 */
function crm_merchant_normalize_payout_status( string $status ): string {
	$status   = sanitize_key( $status );
	$statuses = crm_merchant_payout_statuses();

	return isset( $statuses[ $status ] ) ? $status : CRM_MERCHANT_PAYOUT_STATUS_PAID;
}

/**
 * Label статуса выплаты мерчанту.
 */
function crm_merchant_payout_status_label( string $status ): string {
	$status   = crm_merchant_normalize_payout_status( $status );
	$statuses = crm_merchant_payout_statuses();

	return $statuses[ $status ] ?? $statuses[ CRM_MERCHANT_PAYOUT_STATUS_PAID ];
}

/**
 * Badge-класс статуса выплаты мерчанту.
 */
function crm_merchant_payout_status_badge( string $status ): string {
	$status = crm_merchant_normalize_payout_status( $status );

	if ( $status === CRM_MERCHANT_PAYOUT_STATUS_CONFIRMED ) {
		return 'success';
	}
	if ( $status === CRM_MERCHANT_PAYOUT_STATUS_CANCELLED ) {
		return 'danger';
	}

	return 'warning';
}

/**
 * Причины отмены выплаты мерчанту.
 *
 * @return array<string,string>
 */
function crm_merchant_payout_cancellation_reasons(): array {
	return [
		'duplicate_entry' => 'Случайно внесена повторная выплата',
		'wrong_wallet'    => 'Указан неверный адрес кошелька',
		'tx_not_sent'     => 'Транзакция фактически не была отправлена',
		'test_entry'      => 'Тестовая или ошибочная запись',
		'other'           => 'Другая причина',
	];
}

/**
 * Нормализует причину отмены выплаты мерчанту.
 */
function crm_merchant_normalize_payout_cancellation_reason( string $reason ): string {
	$reason  = sanitize_key( $reason );
	$reasons = crm_merchant_payout_cancellation_reasons();

	return isset( $reasons[ $reason ] ) ? $reason : 'other';
}

/**
 * Label причины отмены выплаты мерчанту.
 */
function crm_merchant_payout_cancellation_reason_label( string $reason ): string {
	$reason  = crm_merchant_normalize_payout_cancellation_reason( $reason );
	$reasons = crm_merchant_payout_cancellation_reasons();

	return $reasons[ $reason ] ?? $reasons['other'];
}

/**
 * Справочник типов наценки / комиссии.
 *
 * @return array<string,string>
 */
function crm_merchant_markup_types(): array {
	return [
		'percent' => 'Процент',
		'fixed'   => 'Фиксированная сумма',
	];
}

/**
 * База, от которой считается merchant percent в RUB -> USDT contour.
 *
 * @return array<string,string>
 */
function crm_merchant_markup_bases(): array {
	return [
		'acquirer_cost' => 'На нашу себестоимость',
		'rapira_rate'   => 'На курс Rapira Ask',
	];
}

/**
 * Нормализует merchant markup basis.
 */
function crm_merchant_normalize_markup_basis( string $basis ): string {
	$basis = sanitize_key( $basis );
	$bases = crm_merchant_markup_bases();

	return isset( $bases[ $basis ] ) ? $basis : 'acquirer_cost';
}

/**
 * Label для базы расчёта merchant markup.
 */
function crm_merchant_markup_basis_label( string $basis, bool $short = false ): string {
	$basis = crm_merchant_normalize_markup_basis( $basis );

	if ( $short ) {
		$labels = [
			'acquirer_cost' => 'Себестоимость',
			'rapira_rate'   => 'Rapira Ask',
		];

		return $labels[ $basis ] ?? $labels['acquirer_cost'];
	}

	$labels = crm_merchant_markup_bases();

	return $labels[ $basis ] ?? $labels['acquirer_cost'];
}

/**
 * Компактный label наценки для CRM-таблиц.
 */
function crm_merchant_markup_display_label( string $type, float $value, string $basis = 'acquirer_cost' ): string {
	$type  = sanitize_key( $type );
	$basis = crm_merchant_normalize_markup_basis( $basis );

	if ( ! isset( crm_merchant_markup_types()[ $type ] ) ) {
		$type = 'percent';
	}

	$value_label = number_format( $value, 8, '.', '' );
	$value_label = rtrim( rtrim( $value_label, '0' ), '.' );
	if ( $value_label === '' ) {
		$value_label = '0';
	}

	if ( $type === 'percent' ) {
		$value_label .= '% · ' . crm_merchant_markup_basis_label( $basis, true );
	}

	return $value_label;
}

/**
 * Режим применения merchant percent к RUB-сумме счёта.
 *
 * @return array<string,string>
 */
function crm_merchant_rub_invoice_markup_modes(): array {
	return [
		'none'       => 'Без надбавки к RUB-сумме',
		'add_on_top' => 'Добавлять процент к RUB-сумме',
	];
}

/**
 * Нормализует режим применения merchant percent к RUB-сумме счёта.
 */
function crm_merchant_normalize_rub_invoice_markup_mode( string $mode ): string {
	$mode  = sanitize_key( $mode );
	$modes = crm_merchant_rub_invoice_markup_modes();

	return isset( $modes[ $mode ] ) ? $mode : 'none';
}

/**
 * Label для режима применения merchant percent к RUB-сумме счёта.
 */
function crm_merchant_rub_invoice_markup_mode_label( string $mode ): string {
	$mode  = crm_merchant_normalize_rub_invoice_markup_mode( $mode );
	$modes = crm_merchant_rub_invoice_markup_modes();

	return $modes[ $mode ] ?? $modes['none'];
}

/**
 * Справочник статусов приглашений.
 *
 * @return array<string,string>
 */
function crm_merchant_invite_statuses(): array {
	return [
		'new'     => 'Новый',
		'used'    => 'Использован',
		'expired' => 'Просрочен',
		'revoked' => 'Отозван',
	];
}

/**
 * Справочник типов ledger-операций.
 *
 * @return array<string,string>
 */
function crm_merchant_wallet_entry_types(): array {
	return [
		'bonus_accrual'       => 'Начисление бонуса',
		'bonus_adjustment'    => 'Корректировка бонуса',
		'bonus_withdrawal'    => 'Списание бонуса',
		'referral_accrual'    => 'Начисление рефералки',
		'referral_adjustment' => 'Корректировка рефералки',
		'manual_credit'       => 'Ручное начисление',
		'manual_debit'        => 'Ручное списание',
		CRM_MERCHANT_WALLET_ENTRY_MERCHANT_ACCRUAL => 'Начисление по paid order',
		CRM_MERCHANT_WALLET_ENTRY_MERCHANT_PAYOUT  => 'Выплата мерчанту',
		CRM_MERCHANT_WALLET_ENTRY_MERCHANT_PAYOUT_REVERSAL => 'Отмена выплаты мерчанту',
	];
}

/**
 * Настройки merchant-контура по умолчанию.
 *
 * @return array<string,string>
 */
function crm_merchants_default_settings(): array {
	return [
		'merchant_invite_ttl_minutes'         => '60',
		'merchant_default_platform_fee_type'  => 'percent',
		'merchant_default_platform_fee_value' => '0',
		'merchant_bonus_enabled'              => '1',
		'merchant_referral_enabled'           => '0',
		'merchant_referral_reward_type'       => 'percent',
		'merchant_referral_reward_value'      => '0',
	];
}

/**
 * Гарантирует наличие merchant-настроек у компании.
 */
function crm_merchants_seed_company_settings( int $company_id ): void {
	if ( $company_id <= 0 ) {
		return;
	}

	global $wpdb;

	foreach ( crm_merchants_default_settings() as $key => $value ) {
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO crm_settings (org_id, setting_key, setting_value) VALUES (%d, %s, %s)',
				$company_id,
				$key,
				$value
			)
		);
	}
}

/**
 * Typed-обёртка над merchant-настройками компании.
 *
 * @return array<string,mixed>
 */
function crm_merchants_get_settings( int $company_id ): array {
	$defaults = crm_merchants_default_settings();

	$fee_type = sanitize_key( (string) crm_get_setting(
		'merchant_default_platform_fee_type',
		$company_id,
		$defaults['merchant_default_platform_fee_type']
	) );

	$referral_type = sanitize_key( (string) crm_get_setting(
		'merchant_referral_reward_type',
		$company_id,
		$defaults['merchant_referral_reward_type']
	) );

	if ( ! isset( crm_merchant_markup_types()[ $fee_type ] ) ) {
		$fee_type = $defaults['merchant_default_platform_fee_type'];
	}
	if ( ! isset( crm_merchant_markup_types()[ $referral_type ] ) ) {
		$referral_type = $defaults['merchant_referral_reward_type'];
	}

	$ttl_minutes = (int) crm_get_setting(
		'merchant_invite_ttl_minutes',
		$company_id,
		$defaults['merchant_invite_ttl_minutes']
	);
	if ( $ttl_minutes <= 0 ) {
		$ttl_minutes = (int) $defaults['merchant_invite_ttl_minutes'];
	}

	return [
		'invite_ttl_minutes'         => $ttl_minutes,
		'default_platform_fee_type'  => $fee_type,
		'default_platform_fee_value' => (string) crm_get_setting(
			'merchant_default_platform_fee_value',
			$company_id,
			$defaults['merchant_default_platform_fee_value']
		),
		'bonus_enabled'              => crm_get_setting(
			'merchant_bonus_enabled',
			$company_id,
			$defaults['merchant_bonus_enabled']
		) === '1',
		'referral_enabled'           => crm_get_setting(
			'merchant_referral_enabled',
			$company_id,
			$defaults['merchant_referral_enabled']
		) === '1',
		'referral_reward_type'       => $referral_type,
		'referral_reward_value'      => (string) crm_get_setting(
			'merchant_referral_reward_value',
			$company_id,
			$defaults['merchant_referral_reward_value']
		),
	];
}

/**
 * Может ли viewer видеть мерчантов конкретной компании.
 * Root на merchant-странице имеет cross-company доступ.
 */
function crm_merchant_viewer_can_access_company( int $viewer_user_id, int $company_id ): bool {
	if ( $company_id <= 0 ) {
		return false;
	}

	if ( crm_is_root( $viewer_user_id ) ) {
		return true;
	}

	$current_company_id = crm_get_current_user_company_id( $viewer_user_id );

	return $current_company_id > 0 && $current_company_id === $company_id;
}

/**
 * Список офисов по наборам компаний.
 *
 * @param int[] $company_ids
 * @return array<int,array<int,array<string,mixed>>>
 */
function crm_get_company_offices_by_company_ids( array $company_ids ): array {
	global $wpdb;

	$company_ids = array_values( array_filter( array_map( 'intval', $company_ids ) ) );
	if ( empty( $company_ids ) ) {
		return [];
	}

	$sql_ids = implode( ',', $company_ids );
	$rows    = $wpdb->get_results(
		"SELECT o.id, o.company_id, o.code, o.name, o.city, o.status, c.name AS company_name
		 FROM crm_company_offices o
		 JOIN crm_companies c ON c.id = o.company_id
		 WHERE o.company_id IN ($sql_ids)
		 ORDER BY c.name ASC, o.is_default DESC, o.sort_order ASC, o.name ASC",
		ARRAY_A
	) ?: [];

	$map = [];
	foreach ( $rows as $row ) {
		$company_id = (int) $row['company_id'];
		if ( ! isset( $map[ $company_id ] ) ) {
			$map[ $company_id ] = [];
		}
		$map[ $company_id ][] = [
			'id'           => (int) $row['id'],
			'code'         => (string) $row['code'],
			'name'         => (string) $row['name'],
			'city'         => (string) ( $row['city'] ?? '' ),
			'status'       => (string) $row['status'],
			'company_name' => (string) $row['company_name'],
		];
	}

	return $map;
}

/**
 * Список мерчантов по компаниям для dropdown'ов.
 *
 * @param int[] $company_ids
 * @return array<int,array<int,array<string,mixed>>>
 */
function crm_get_merchants_by_company_ids( array $company_ids ): array {
	global $wpdb;

	$company_ids = array_values( array_filter( array_map( 'intval', $company_ids ) ) );
	if ( empty( $company_ids ) ) {
		return [];
	}

	$sql_ids = implode( ',', $company_ids );
	$rows    = $wpdb->get_results(
		"SELECT id, company_id, chat_id, telegram_username, name, status
		 FROM crm_merchants
		 WHERE company_id IN ($sql_ids)
		   AND status != 'archived'
		 ORDER BY name ASC, id ASC",
		ARRAY_A
	) ?: [];

	$map = [];
	foreach ( $rows as $row ) {
		$company_id = (int) $row['company_id'];
		if ( ! isset( $map[ $company_id ] ) ) {
			$map[ $company_id ] = [];
		}
		$map[ $company_id ][] = [
			'id'                => (int) $row['id'],
			'chat_id'           => (string) $row['chat_id'],
			'telegram_username' => (string) ( $row['telegram_username'] ?? '' ),
			'name'              => (string) ( $row['name'] ?? '' ),
			'status'            => (string) $row['status'],
		];
	}

	return $map;
}

/**
 * Возвращает company_id мерчанта.
 */
function crm_get_merchant_company_id( int $merchant_id ): int {
	global $wpdb;

	if ( $merchant_id <= 0 ) {
		return 0;
	}

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT company_id FROM crm_merchants WHERE id = %d LIMIT 1',
			$merchant_id
		)
	);
}

/**
 * Публичная загрузка карточки мерчанта по ID.
 */
function crm_get_merchant_by_id( int $merchant_id ): ?object {
	global $wpdb;

	if ( $merchant_id <= 0 ) {
		return null;
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT m.*,
			        c.name AS company_name,
			        c.code AS company_code,
			        o.name AS office_name
			 FROM crm_merchants m
			 JOIN crm_companies c ON c.id = m.company_id
			 LEFT JOIN crm_company_offices o ON o.id = m.office_id
			 WHERE m.id = %d
			 LIMIT 1",
			$merchant_id
		)
	);

	return $row instanceof stdClass ? $row : null;
}

/**
 * Нормализует код направления мерчанта.
 */
function crm_merchant_normalize_invoice_direction_code( string $code ): string {
	$code = trim( $code );
	if ( $code === '' ) {
		return '';
	}

	if ( function_exists( 'crm_normalize_legacy_pair_code' ) ) {
		$code = (string) crm_normalize_legacy_pair_code( $code );
	} else {
		$code = strtoupper( $code );
	}

	if ( function_exists( 'crm_root_get_pair_definition' ) && ! crm_root_get_pair_definition( $code ) ) {
		return '';
	}

	return $code;
}

/**
 * Парсит список кодов направлений мерчанта из массива / JSON / CSV.
 *
 * @param mixed $raw_value
 * @return string[]
 */
function crm_merchant_parse_invoice_direction_codes( $raw_value ): array {
	if ( is_array( $raw_value ) ) {
		$items = $raw_value;
	} else {
		$raw_text = trim( (string) $raw_value );
		if ( $raw_text === '' ) {
			return [];
		}

		$decoded = json_decode( $raw_text, true );
		if ( is_array( $decoded ) ) {
			$items = $decoded;
		} else {
			$items = preg_split( '/\s*,\s*/', $raw_text, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		}
	}

	$normalized_map = [];
	foreach ( $items as $item ) {
		$code = crm_merchant_normalize_invoice_direction_code( (string) $item );
		if ( $code !== '' ) {
			$normalized_map[ $code ] = $code;
		}
	}

	$result      = [];
	$definitions = function_exists( 'crm_company_exchange_pair_definitions' ) ? crm_company_exchange_pair_definitions() : [];
	if ( ! empty( $definitions ) ) {
		foreach ( $definitions as $pair ) {
			$code = (string) ( $pair['code'] ?? '' );
			if ( $code !== '' && isset( $normalized_map[ $code ] ) ) {
				$result[] = $code;
			}
		}

		return $result;
	}

	return array_values( $normalized_map );
}

/**
 * Возвращает направления, включённые на уровне компании.
 *
 * @return string[]
 */
function crm_merchant_company_enabled_invoice_directions( int $company_id ): array {
	if ( $company_id <= 0 ) {
		return [];
	}

	if ( function_exists( 'crm_company_get_enabled_invoice_directions' ) ) {
		return array_values( array_map( 'strval', crm_company_get_enabled_invoice_directions( $company_id ) ) );
	}

	return [];
}

/**
 * Фильтрует merchant directions по company-scoped allowlist.
 *
 * @param string[] $direction_codes
 * @return string[]
 */
function crm_merchant_filter_invoice_directions_for_company( int $company_id, array $direction_codes ): array {
	$company_enabled = crm_merchant_company_enabled_invoice_directions( $company_id );
	if ( empty( $company_enabled ) ) {
		return [];
	}

	$company_enabled_map = array_fill_keys( $company_enabled, true );
	$requested_map       = [];

	foreach ( $direction_codes as $direction_code ) {
		$direction_code = crm_merchant_normalize_invoice_direction_code( (string) $direction_code );
		if ( $direction_code !== '' && isset( $company_enabled_map[ $direction_code ] ) ) {
			$requested_map[ $direction_code ] = $direction_code;
		}
	}

	$filtered = [];
	foreach ( $company_enabled as $direction_code ) {
		if ( isset( $requested_map[ $direction_code ] ) ) {
			$filtered[] = $direction_code;
		}
	}

	return $filtered;
}

/**
 * Валидирует merchant directions в рамках текущей компании.
 *
 * @param mixed $raw_value
 * @return string[]|WP_Error
 */
function crm_merchant_validate_invoice_directions_for_company( int $company_id, $raw_value ) {
	if ( $company_id <= 0 ) {
		return new WP_Error( 'invalid_company_scope', 'У мерчанта нет валидной компании.' );
	}

	$company_enabled = crm_merchant_company_enabled_invoice_directions( $company_id );
	if ( empty( $company_enabled ) ) {
		return new WP_Error( 'company_directions_disabled', 'Для компании не включено ни одно направление обмена.' );
	}

	$parsed   = crm_merchant_parse_invoice_direction_codes( $raw_value );
	$filtered = crm_merchant_filter_invoice_directions_for_company( $company_id, $parsed );

	if ( empty( $filtered ) ) {
		return new WP_Error( 'empty_invoice_directions', 'Выберите хотя бы одно направление обмена для мерчанта.' );
	}

	if ( count( $filtered ) !== count( $parsed ) ) {
		return new WP_Error( 'invalid_invoice_directions', 'Выбрано направление, которое недоступно в текущей компании.' );
	}

	return $filtered;
}

/**
 * Возвращает effective merchant directions. Если custom allowlist не сохранён, мерчант наследует направления компании.
 *
 * @param mixed $raw_value
 * @return string[]
 */
function crm_merchant_resolve_invoice_directions_for_company( int $company_id, $raw_value, bool $fallback_to_company = true ): array {
	$company_enabled = crm_merchant_company_enabled_invoice_directions( $company_id );
	if ( empty( $company_enabled ) ) {
		return [];
	}

	$has_custom_value = false;
	if ( is_array( $raw_value ) ) {
		$has_custom_value = ! empty( $raw_value );
	} else {
		$raw_text         = trim( (string) $raw_value );
		$has_custom_value = $raw_text !== '' && $raw_text !== '[]';
	}

	if ( ! $has_custom_value ) {
		return $fallback_to_company ? $company_enabled : [];
	}

	return crm_merchant_filter_invoice_directions_for_company(
		$company_id,
		crm_merchant_parse_invoice_direction_codes( $raw_value )
	);
}

/**
 * Возвращает effective merchant directions из строки мерчанта.
 *
 * @param object|array<string,mixed> $merchant
 * @return string[]
 */
function crm_merchant_resolve_invoice_directions_from_row( $merchant, bool $fallback_to_company = true ): array {
	$row = is_object( $merchant ) ? get_object_vars( $merchant ) : (array) $merchant;

	return crm_merchant_resolve_invoice_directions_for_company(
		(int) ( $row['company_id'] ?? 0 ),
		$row['enabled_invoice_directions_json'] ?? null,
		$fallback_to_company
	);
}

/**
 * Есть ли у мерчанта custom allowlist направлений.
 *
 * @param object|array<string,mixed> $merchant
 */
function crm_merchant_has_custom_invoice_directions( $merchant ): bool {
	$row = is_object( $merchant ) ? get_object_vars( $merchant ) : (array) $merchant;
	$raw = $row['enabled_invoice_directions_json'] ?? null;

	if ( is_array( $raw ) ) {
		return ! empty( $raw );
	}

	$raw_text = trim( (string) $raw );

	return $raw_text !== '' && $raw_text !== '[]';
}

/**
 * Кодирует merchant directions в JSON для хранения в crm_merchants.
 *
 * @param string[] $direction_codes
 */
function crm_merchant_encode_invoice_directions( array $direction_codes ): ?string {
	if ( empty( $direction_codes ) ) {
		return null;
	}

	$encoded = wp_json_encode(
		array_values( array_map( 'strval', $direction_codes ) ),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);

	return is_string( $encoded ) ? $encoded : null;
}

/**
 * Возвращает effective directions мерчанта по ID.
 *
 * @return string[]
 */
function crm_merchant_get_enabled_invoice_directions( int $merchant_id ): array {
	$merchant = crm_get_merchant_by_id( $merchant_id );

	return $merchant ? crm_merchant_resolve_invoice_directions_from_row( $merchant, true ) : [];
}

/**
 * Проверяет, доступно ли направление конкретному мерчанту.
 *
 * @param object|array<string,mixed> $merchant
 */
function crm_merchant_has_invoice_direction( $merchant, string $direction_code ): bool {
	$direction_code = crm_merchant_normalize_invoice_direction_code( $direction_code );
	if ( $direction_code === '' ) {
		return false;
	}

	foreach ( crm_merchant_resolve_invoice_directions_from_row( $merchant, true ) as $enabled_code ) {
		if ( $enabled_code === $direction_code ) {
			return true;
		}
	}

	return false;
}

/**
 * Рендерит HTML-бейджи доступных направлений мерчанта.
 */
function crm_merchant_render_exchange_pair_badges_html( array $direction_codes ): string {
	if ( function_exists( 'crm_company_render_exchange_pair_badges_html' ) ) {
		return crm_company_render_exchange_pair_badges_html( $direction_codes );
	}

	$direction_codes = array_values( array_filter( array_map( 'strval', $direction_codes ) ) );

	ob_start();
	if ( empty( $direction_codes ) ) :
		?>
		<span class="badge badge-secondary m-r-2">Направления выключены</span>
		<?php
	else :
		foreach ( $direction_codes as $direction_code ) :
			?>
			<span class="badge badge-success m-r-2"><?php echo esc_html( $direction_code ); ?></span>
			<?php
		endforeach;
	endif;

	return (string) ob_get_clean();
}

/**
 * Нормализует RUB-сумму из формы/Telegram.
 */
function crm_merchant_normalize_rub_amount( $value ): float {
	if ( is_string( $value ) ) {
		$value = preg_replace( '/\s+/u', '', $value );
		$value = str_replace( ',', '.', (string) $value );
	}

	if ( ! is_numeric( $value ) ) {
		return 0.0;
	}

	return round( max( 0, (float) $value ), 2 );
}

/**
 * Нормализует USDT-сумму из API/формы.
 */
function crm_merchant_normalize_usdt_amount( $value ): float {
	if ( is_string( $value ) ) {
		$value = preg_replace( '/\s+/u', '', $value );
		$value = str_replace( ',', '.', (string) $value );
	}

	if ( ! is_numeric( $value ) ) {
		return 0.0;
	}

	return round( max( 0, (float) $value ), 8 );
}

/**
 * Merchant-facing RUB -> USDT course is a commercial figure with 4 decimal places.
 * The same rounded rate must be used both in the receipt and in payout calculation.
 */
function crm_merchant_rub_invoice_rate_decimals(): int {
	return 4;
}

/**
 * Merchant-facing USDT payout is fixed to 4 decimal places to match the visible rate.
 */
function crm_merchant_rub_invoice_payable_decimals(): int {
	return 4;
}

/**
 * Builds normalized economics for merchant RUB -> USDT invoices.
 *
 * @return array<string,mixed>
 */
function crm_merchant_calculate_rub_invoice_economics(
	float $rapira_ask,
	float $acquirer_markup_percent,
	float $merchant_markup_percent,
	string $merchant_markup_basis = 'acquirer_cost',
	float $requested_rub = 0.0,
	string $rub_invoice_markup_mode = 'none'
): array {
	$merchant_markup_basis       = crm_merchant_normalize_markup_basis( $merchant_markup_basis );
	$rub_invoice_markup_mode     = crm_merchant_normalize_rub_invoice_markup_mode( $rub_invoice_markup_mode );
	$requested_rub               = round( max( 0, $requested_rub ), 2 );
	$rapira_ask                = round( max( 0, $rapira_ask ), 8 );
	$acquirer_rate_raw         = round( $rapira_ask * ( 1 + ( $acquirer_markup_percent / 100 ) ), 8 );
	$merchant_rate_raw         = $merchant_markup_basis === 'rapira_rate'
		? round( $rapira_ask * ( 1 + ( $merchant_markup_percent / 100 ) ), 8 )
		: round( $acquirer_rate_raw * ( 1 + ( $merchant_markup_percent / 100 ) ), 8 );
	$payment_amount_rub        = $rub_invoice_markup_mode === 'add_on_top' && $merchant_markup_percent > 0 && $requested_rub > 0
		? round( $requested_rub * ( 1 + ( $merchant_markup_percent / 100 ) ), 2 )
		: $requested_rub;
	$merchant_markup_added_rub = round( max( 0, $payment_amount_rub - $requested_rub ), 2 );
	$merchant_rate_commercial  = round( $merchant_rate_raw, crm_merchant_rub_invoice_rate_decimals() );
	$expected_acquirer_gross   = ( $acquirer_rate_raw > 0 && $payment_amount_rub > 0 )
		? round( $payment_amount_rub / $acquirer_rate_raw, 8 )
		: 0.0;
	$merchant_payable_usdt     = ( $merchant_rate_commercial > 0 && $payment_amount_rub > 0 )
		? round( $payment_amount_rub / $merchant_rate_commercial, crm_merchant_rub_invoice_payable_decimals() )
		: 0.0;

	return [
		'requested_rub_input'       => $requested_rub,
		'payment_amount_rub'        => $payment_amount_rub,
		'merchant_markup_added_rub' => $merchant_markup_added_rub,
		'rapira_ask'               => $rapira_ask,
		'acquirer_rate_raw'        => $acquirer_rate_raw,
		'merchant_markup_basis'    => $merchant_markup_basis,
		'rub_invoice_markup_mode'  => $rub_invoice_markup_mode,
		'merchant_rate_raw'        => $merchant_rate_raw,
		'merchant_rate_commercial' => $merchant_rate_commercial,
		'expected_acquirer_gross'  => $expected_acquirer_gross,
		'merchant_payable_usdt'    => $merchant_payable_usdt,
	];
}

/**
 * Проверяет, готов ли мерчант к новому RUB -> USDT invoice flow.
 *
 * @param object|array<string,mixed> $merchant
 * @return array<string,mixed>
 */
function crm_merchant_validate_rub_invoice_prerequisites( $merchant ): array {
	$row = is_object( $merchant ) ? get_object_vars( $merchant ) : (array) $merchant;

	$merchant_id          = isset( $row['id'] ) ? (int) $row['id'] : 0;
	$company_id           = isset( $row['company_id'] ) ? (int) $row['company_id'] : 0;
	$merchant_status      = (string) ( $row['status'] ?? '' );
	$merchant_markup_type = sanitize_key( (string) ( $row['base_markup_type'] ?? 'percent' ) );
	$merchant_markup_basis= crm_merchant_normalize_markup_basis( (string) ( $row['base_markup_basis'] ?? 'acquirer_cost' ) );
	$rub_invoice_markup_mode = crm_merchant_normalize_rub_invoice_markup_mode( (string) ( $row['rub_invoice_markup_mode'] ?? 'none' ) );
	$merchant_markup_raw  = isset( $row['base_markup_value'] ) ? (float) $row['base_markup_value'] : 0.0;
	$order_currency       = crm_fintech_normalize_kanyon_order_currency(
		crm_get_setting( 'fintech_pay2day_order_currency', $company_id, '' )
	);
	$active_provider      = crm_fintech_normalize_provider_code(
		(string) crm_get_setting( 'fintech_active_provider', $company_id, '' )
	);
	$config_status        = $company_id > 0 ? crm_fintech_get_configuration_status( $company_id ) : [];
	$company_markup       = $company_id > 0 ? crm_fintech_get_kanyon_rapira_markup_percent( $company_id ) : 0.0;

	$result = [
		'success'                  => false,
		'merchant_id'              => $merchant_id,
		'company_id'               => $company_id,
		'merchant_status'          => $merchant_status,
		'merchant_markup_type'     => $merchant_markup_type,
		'merchant_markup_basis'    => $merchant_markup_basis,
		'rub_invoice_markup_mode'  => $rub_invoice_markup_mode,
		'merchant_markup_percent'  => round( max( 0, $merchant_markup_raw ), 8 ),
		'company_order_currency'   => $order_currency,
		'active_provider'          => $active_provider,
		'acquirer_markup_percent'  => round( max( 0, $company_markup ), 8 ),
		'config_status'            => $config_status,
		'error_code'               => 'validation_failed',
		'error'                    => 'Merchant RUB invoice prerequisites are not satisfied.',
	];

	if ( $merchant_id <= 0 ) {
		$result['error'] = 'Мерчант не найден.';
		return $result;
	}

	if ( $company_id <= 0 ) {
		$result['error'] = 'У мерчанта нет валидной компании.';
		return $result;
	}

	if ( $merchant_status !== CRM_MERCHANT_STATUS_ACTIVE ) {
		$result['error'] = 'RUB invoice доступен только для активного мерчанта.';
		return $result;
	}

	if ( $order_currency !== 'RUB' ) {
		$result['error'] = sprintf(
			'У компании сейчас включён legacy Kanyon-контур `%s`. Новый merchant RUB -> USDT flow доступен только при `fintech_pay2day_order_currency = RUB`.',
			$order_currency !== '' ? $order_currency : 'не задано'
		);
		return $result;
	}

	if ( $active_provider !== Fintech_Payment_Gateway::PROVIDER_KANYON ) {
		$result['error'] = sprintf(
			'Merchant RUB -> USDT flow пока работает только через Kanyon. Сейчас активный провайдер компании: %s.',
			$active_provider !== '' ? crm_fintech_provider_label( $active_provider ) : 'не выбран'
		);
		return $result;
	}

	if ( ! crm_fintech_is_provider_allowed( $company_id, Fintech_Payment_Gateway::PROVIDER_KANYON ) ) {
		$result['error'] = 'Kanyon отключён для этой компании. Обратитесь к root-администратору.';
		return $result;
	}

	if ( function_exists( 'crm_company_contour_is_enabled' ) && ! crm_company_contour_is_enabled( $company_id, 'RUB_USDT' ) ) {
		$result['error'] = 'Направление RUB -> USDT не включено для этой компании. Обратитесь к root-администратору.';
		return $result;
	}

	if ( ! crm_merchant_has_invoice_direction( $merchant, 'RUB_USDT' ) ) {
		$result['error'] = 'Направление RUB -> USDT отключено для этого мерчанта.';
		return $result;
	}

	if ( empty( $config_status['is_configured'] ) ) {
		$missing_labels = array_map(
			static fn( $item ) => (string) ( $item['label'] ?? '' ),
			$config_status['missing_fields'] ?? []
		);
		$missing_labels = array_values( array_filter( $missing_labels ) );
		$result['error'] = 'Kanyon не настроен для этой компании.'
			. ( ! empty( $missing_labels ) ? ' Не хватает: ' . implode( ', ', $missing_labels ) . '.' : '' );
		return $result;
	}

	if ( $merchant_markup_type !== 'percent' ) {
		$result['error'] = 'Новый RUB -> USDT flow пока поддерживает только процентную наценку мерчанта. Для fixed markup нужна отдельная формула.';
		return $result;
	}

	if ( $merchant_markup_basis === 'rapira_rate' && $result['merchant_markup_percent'] + 0.00000001 < $result['acquirer_markup_percent'] ) {
		$result['error'] = 'Для режима `Напрямую от курса Rapira` процент мерчанта не может быть ниже company markup эквайринг-партнёра, иначе счёт уйдёт ниже себестоимости.';
		return $result;
	}

	$result['success'] = true;
	$result['error']   = null;

	return $result;
}

/**
 * Проверяет, готов ли мерчант к legacy USDT -> RUB invoice flow через Kanyon orderAmount.
 *
 * @param object|array<string,mixed> $merchant
 * @return array<string,mixed>
 */
function crm_merchant_validate_usdt_invoice_prerequisites( $merchant ): array {
	$row = is_object( $merchant ) ? get_object_vars( $merchant ) : (array) $merchant;

	$merchant_id     = isset( $row['id'] ) ? (int) $row['id'] : 0;
	$company_id      = isset( $row['company_id'] ) ? (int) $row['company_id'] : 0;
	$merchant_status = (string) ( $row['status'] ?? '' );
	$order_currency  = crm_fintech_normalize_kanyon_order_currency(
		crm_get_setting( 'fintech_pay2day_order_currency', $company_id, '' )
	);
	$active_provider = crm_fintech_normalize_provider_code(
		(string) crm_get_setting( 'fintech_active_provider', $company_id, '' )
	);
	$config_status = $company_id > 0 ? crm_fintech_get_configuration_status( $company_id ) : [];

	$result = [
		'success'                => false,
		'merchant_id'            => $merchant_id,
		'company_id'             => $company_id,
		'merchant_status'        => $merchant_status,
		'company_order_currency' => $order_currency,
		'active_provider'        => $active_provider,
		'config_status'          => $config_status,
		'error_code'            => 'validation_failed',
		'error'                  => 'Merchant USDT invoice prerequisites are not satisfied.',
	];

	if ( $merchant_id <= 0 ) {
		$result['error'] = 'Мерчант не найден.';
		return $result;
	}

	if ( $company_id <= 0 ) {
		$result['error'] = 'У мерчанта нет валидной компании.';
		return $result;
	}

	if ( $merchant_status !== CRM_MERCHANT_STATUS_ACTIVE ) {
		$result['error'] = 'USDT invoice доступен только для активного мерчанта.';
		return $result;
	}

	if ( $order_currency !== 'USDT' ) {
		$result['error'] = sprintf(
			'Legacy merchant RUB -> USDT flow через orderAmount доступен только при `fintech_pay2day_order_currency = USDT`. У этой компании сейчас настроена валюта %s.',
			$order_currency !== '' ? $order_currency : 'не задано'
		);
		return $result;
	}

	if ( $active_provider !== Fintech_Payment_Gateway::PROVIDER_KANYON ) {
		$result['error'] = sprintf(
			'Legacy merchant RUB -> USDT flow через orderAmount пока работает только через Kanyon. Сейчас активный провайдер компании: %s.',
			$active_provider !== '' ? crm_fintech_provider_label( $active_provider ) : 'не выбран'
		);
		return $result;
	}

	if ( ! crm_fintech_is_provider_allowed( $company_id, Fintech_Payment_Gateway::PROVIDER_KANYON ) ) {
		$result['error'] = 'Kanyon отключён для этой компании. Обратитесь к root-администратору.';
		return $result;
	}

	if ( function_exists( 'crm_company_contour_is_enabled' ) && ! crm_company_contour_is_enabled( $company_id, 'RUB_USDT' ) ) {
		$result['error'] = 'Направление RUB -> USDT не включено для этой компании. Обратитесь к root-администратору.';
		return $result;
	}

	if ( ! crm_merchant_has_invoice_direction( $merchant, 'RUB_USDT' ) ) {
		$result['error'] = 'Направление RUB -> USDT отключено для этого мерчанта.';
		return $result;
	}

	if ( empty( $config_status['is_configured'] ) ) {
		$missing_labels = array_map(
			static fn( $item ) => (string) ( $item['label'] ?? '' ),
			$config_status['missing_fields'] ?? []
		);
		$missing_labels = array_values( array_filter( $missing_labels ) );
		$result['error'] = 'Kanyon не настроен для этой компании.'
			. ( ! empty( $missing_labels ) ? ' Не хватает: ' . implode( ', ', $missing_labels ) . '.' : '' );
		return $result;
	}

	$result['success'] = true;
	$result['error']   = null;

	return $result;
}

/**
 * Создаёт merchant invoice в legacy RUB -> USDT contour, где мерчант задаёт сумму в USDT,
 * а клиент оплачивает счёт в RUB через Kanyon orderAmount.
 *
 * @param array<string,mixed> $args
 * @return array<string,mixed>
 */
function crm_merchant_create_usdt_invoice( int $merchant_id, float $requested_usdt, array $args = [] ): array {
	$source_channel      = sanitize_key( (string) ( $args['source_channel'] ?? 'merchant_api' ) );
	$created_by_user_id  = isset( $args['created_by_user_id'] ) ? (int) $args['created_by_user_id'] : null;
	$merchant            = crm_get_merchant_by_id( $merchant_id );
	$requested_usdt      = crm_merchant_normalize_usdt_amount( $requested_usdt );
	$external_order_id   = sanitize_text_field( wp_unslash( (string) ( $args['external_order_id'] ?? '' ) ) );
	$idempotency_key     = sanitize_text_field( wp_unslash( (string) ( $args['idempotency_key'] ?? '' ) ) );

	$result = [
		'success'                => false,
		'error_code'             => 'internal_error',
		'error'                  => 'Не удалось создать merchant invoice.',
		'warning'                => null,
		'order_db_id'            => null,
		'merchant_order_id'      => '',
		'provider_order_id'      => '',
		'external_order_id'      => $external_order_id,
		'payment_link'           => '',
		'qrc_id'                 => '',
		'qr_url'                 => null,
		'provider'               => '',
		'status_code'            => 'created',
		'company_id'             => 0,
		'merchant_id'            => $merchant_id,
		'requested_usdt'         => $requested_usdt,
		'merchant_payable_usdt'  => $requested_usdt,
		'platform_fee_usdt'      => 0.0,
		'kanyon_gross_usdt'      => $requested_usdt,
		'payment_amount_rub'     => null,
		'payment_purpose'        => '',
	];

	if ( ! $merchant ) {
		$result['error_code'] = 'not_found';
		$result['error'] = 'Мерчант не найден.';
		return $result;
	}

	$precheck = crm_merchant_validate_usdt_invoice_prerequisites( $merchant );
	$result['company_id'] = (int) $precheck['company_id'];
	if ( empty( $precheck['success'] ) ) {
		$result['error_code'] = (string) ( $precheck['error_code'] ?? 'validation_failed' );
		$result['error'] = (string) ( $precheck['error'] ?? 'Merchant USDT invoice prerequisites are not satisfied.' );
		return $result;
	}

	if ( $requested_usdt <= 0 ) {
		$result['error_code'] = 'validation_failed';
		$result['error'] = 'Сумма счёта должна быть больше нуля.';
		return $result;
	}

	$company_id              = (int) $precheck['company_id'];
	$merchant_name           = trim( (string) ( $merchant->name ?? '' ) );
	$merchant_label          = $merchant_name !== '' ? $merchant_name : 'Merchant #' . $merchant_id;
	$custom_payment_purpose  = crm_fintech_normalize_payment_purpose( $args['payment_purpose'] ?? '' );
	$default_payment_purpose = crm_fintech_get_pay2day_default_payment_purpose( $company_id );
	$payment_purpose         = $custom_payment_purpose !== '' ? $custom_payment_purpose : $default_payment_purpose;
	$payment_purpose_source  = $custom_payment_purpose !== ''
		? 'custom'
		: ( $default_payment_purpose !== '' ? 'default' : 'automatic' );

	$result['payment_purpose'] = $payment_purpose;

	$description = $payment_purpose !== ''
		? $payment_purpose
		: sprintf(
			'%s merchant USDT invoice %s USDT',
			$merchant_label,
			number_format( $requested_usdt, 2, '.', '' )
		);

	Fintech_Payment_Gateway::set_company_id( $company_id );
	$invoice = fintech_create_invoice( $requested_usdt, null, $description );

	if ( empty( $invoice['success'] ) ) {
		$result['error_code'] = 'provider_error';
		crm_log( 'merchant.invoice.gateway_failed', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'create',
			'message'     => 'Kanyon не создал merchant USDT invoice.',
			'target_type' => 'merchant',
			'target_id'   => $merchant_id,
			'org_id'      => $company_id,
			'is_success'  => false,
			'context'     => [
				'requested_usdt'    => $requested_usdt,
				'payment_purpose'   => $payment_purpose,
				'external_order_id' => $external_order_id !== '' ? $external_order_id : null,
				'error'             => (string) ( $invoice['error'] ?? '' ),
			],
		] );

		$result['error'] = (string) ( $invoice['error'] ?? 'Gateway error' );
		return $result;
	}

	$payload_mode       = (string) ( $invoice['payloadMode'] ?? 'orderAmount' );
	$payment_amount_rub = isset( $invoice['paymentAmountValue'] ) && $invoice['paymentAmountValue'] !== null
		? round( (float) $invoice['paymentAmountValue'], 2 )
		: null;

	$result['warning']            = isset( $invoice['warning'] ) ? (string) $invoice['warning'] : null;
	$result['provider']           = (string) ( $invoice['provider'] ?? Fintech_Payment_Gateway::PROVIDER_KANYON );
	$result['merchant_order_id']  = (string) ( $invoice['merchantOrderId'] ?? '' );
	$result['provider_order_id']  = (string) ( $invoice['orderId'] ?? '' );
	$result['status_code']        = function_exists( '_crm_fintech_map_status' )
		? _crm_fintech_map_status( (string) ( $invoice['providerStatus'] ?? 'CREATED' ) )
		: 'created';
	$result['payment_link']       = (string) ( $invoice['payload'] ?? '' );
	$result['qrc_id']             = (string) ( $invoice['qrcId'] ?? '' );
	$result['payment_amount_rub'] = $payment_amount_rub;

	$merchant_meta = [
		'merchant_flow'           => 'rub_usdt_kanyon_order_amount',
		'kanyon_payload_mode'     => $payload_mode,
		'payment_purpose'         => $payment_purpose !== '' ? $payment_purpose : null,
		'payment_purpose_source'  => $payment_purpose_source,
		'requested_amount_currency' => 'USDT',
		'payment_currency_code'   => 'RUB',
		'settlement_currency_code'=> 'USDT',
	];

	$order_db_id = crm_fintech_save_order(
		$invoice,
		$company_id,
		$source_channel !== '' ? $source_channel : 'merchant_api',
		$created_by_user_id,
		[
			'merchant_id'                 => $merchant_id,
			'created_for_type'            => 'merchant',
			'local_order_ref'             => $external_order_id !== '' ? $external_order_id : null,
			'idempotency_key'             => $idempotency_key !== '' ? $idempotency_key : null,
			'amount_asset_code'           => 'USDT',
			'amount_asset_value'          => number_format( $requested_usdt, 8, '.', '' ),
			'payment_currency_code'       => 'RUB',
			'payment_amount_value'        => $payment_amount_rub !== null ? number_format( $payment_amount_rub, 2, '.', '' ) : null,
			'merchant_requested_rub_value'=> null,
			'merchant_payable_value'      => number_format( $requested_usdt, 8, '.', '' ),
			'merchant_markup_value'       => number_format( 0, 8, '.', '' ),
			'platform_fee_value'          => number_format( 0, 8, '.', '' ),
			'merchant_profit_value'       => number_format( 0, 8, '.', '' ),
			'referral_reward_value'       => number_format( 0, 8, '.', '' ),
			'meta_json'                   => wp_json_encode(
				[
					'merchant_flow'      => 'rub_usdt_kanyon_order_amount',
					'merchant_id'        => $merchant_id,
					'merchant_name'      => $merchant_label,
					'external_order_id'  => $external_order_id !== '' ? $external_order_id : null,
					'payment_purpose'    => $payment_purpose !== '' ? $payment_purpose : null,
					'payment_purpose_source' => $payment_purpose_source,
				],
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
			'merchant_meta_json'          => wp_json_encode(
				$merchant_meta,
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
			'notes'                       => 'Merchant RUB -> USDT invoice created via Kanyon orderAmount flow.',
		]
	);

	if ( $order_db_id === null || $order_db_id <= 0 ) {
		$result['error_code'] = 'internal_error';
		crm_log( 'merchant.invoice.db_save_failed', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'create',
			'message'     => 'Merchant USDT invoice создан у провайдера, но не сохранился локально.',
			'target_type' => 'merchant',
			'target_id'   => $merchant_id,
			'org_id'      => $company_id,
			'is_success'  => false,
			'context'     => [
				'merchant_order_id' => (string) ( $invoice['merchantOrderId'] ?? '' ),
				'provider_order_id' => (string) ( $invoice['orderId'] ?? '' ),
				'requested_usdt'    => $requested_usdt,
				'external_order_id' => $external_order_id !== '' ? $external_order_id : null,
				'payment_purpose'   => $payment_purpose,
			],
		] );

		$result['error'] = 'Счёт создан у провайдера, но не сохранился в Malibu.';
		return $result;
	}

	$result['order_db_id'] = $order_db_id;

	if ( $result['payment_link'] !== '' && $result['qrc_id'] !== '' ) {
		$result['qr_url'] = crm_fintech_qr_url(
			$result['payment_link'],
			$result['qrc_id'],
			$result['merchant_order_id'] !== '' ? $result['merchant_order_id'] : (string) $order_db_id
		);
	}

	crm_log( 'merchant.invoice.created', [
		'category'    => 'payments',
		'level'       => 'info',
		'action'      => 'create',
		'message'     => 'Создан merchant RUB -> USDT invoice через Kanyon orderAmount.',
		'target_type' => 'payment_order',
		'target_id'   => $order_db_id,
		'org_id'      => $company_id,
		'is_success'  => true,
		'context'     => [
			'merchant_id'         => $merchant_id,
			'merchant_order_id'   => $result['merchant_order_id'],
			'provider_order_id'   => $result['provider_order_id'],
			'requested_usdt'      => $requested_usdt,
			'payment_amount_rub'  => $payment_amount_rub,
			'payload_mode'        => $payload_mode,
			'external_order_id'   => $external_order_id !== '' ? $external_order_id : null,
			'payment_purpose'     => $payment_purpose,
			'payment_purpose_source' => $payment_purpose_source,
		],
	] );

	$result['success'] = true;
	$result['error_code'] = null;
	$result['error']   = null;

	return $result;
}

/**
 * Создаёт merchant invoice в новом RUB -> USDT contour.
 *
 * @param array<string,mixed> $args
 * @return array<string,mixed>
 */
function crm_merchant_create_rub_invoice( int $merchant_id, float $requested_rub, array $args = [] ): array {
	$source_channel      = sanitize_key( (string) ( $args['source_channel'] ?? 'telegram_merchant' ) );
	$created_by_user_id  = isset( $args['created_by_user_id'] ) ? (int) $args['created_by_user_id'] : null;
	$merchant            = crm_get_merchant_by_id( $merchant_id );
	$requested_rub       = crm_merchant_normalize_rub_amount( $requested_rub );
	$external_order_id   = sanitize_text_field( wp_unslash( (string) ( $args['external_order_id'] ?? '' ) ) );
	$idempotency_key     = sanitize_text_field( wp_unslash( (string) ( $args['idempotency_key'] ?? '' ) ) );

	$result = [
		'success'                => false,
		'error_code'             => 'internal_error',
		'error'                  => 'Не удалось создать merchant invoice.',
		'warning'                => null,
		'order_db_id'            => null,
		'merchant_order_id'      => '',
		'provider_order_id'      => '',
		'external_order_id'      => $external_order_id,
		'payment_link'           => '',
		'qrc_id'                 => '',
		'qr_url'                 => null,
		'provider'               => '',
		'status_code'            => 'created',
		'company_id'             => 0,
		'merchant_id'            => $merchant_id,
		'requested_rub'          => $requested_rub,
		'merchant_payable_usdt'  => 0.0,
		'platform_fee_usdt'      => 0.0,
		'kanyon_gross_usdt'      => 0.0,
		'merchant_markup_basis'  => 'acquirer_cost',
		'rub_invoice_markup_mode'=> 'none',
		'merchant_markup_added_rub' => 0.0,
		'rapira_ask'             => null,
		'acquirer_rate'          => null,
		'merchant_rate'          => null,
		'payment_amount_rub'     => $requested_rub,
		'payment_purpose'        => '',
	];

	if ( ! $merchant ) {
		$result['error_code'] = 'not_found';
		$result['error'] = 'Мерчант не найден.';
		return $result;
	}

	$precheck = crm_merchant_validate_rub_invoice_prerequisites( $merchant );
	$result['company_id'] = (int) $precheck['company_id'];
	if ( empty( $precheck['success'] ) ) {
		$result['error_code'] = (string) ( $precheck['error_code'] ?? 'validation_failed' );
		$result['error'] = (string) ( $precheck['error'] ?? 'Merchant RUB invoice prerequisites are not satisfied.' );
		return $result;
	}

	if ( $requested_rub <= 0 ) {
		$result['error_code'] = 'validation_failed';
		$result['error'] = 'Сумма счёта должна быть больше нуля.';
		return $result;
	}

	$company_id               = (int) $precheck['company_id'];
	$acquirer_markup_percent  = (float) $precheck['acquirer_markup_percent'];
	$merchant_markup_percent  = (float) $precheck['merchant_markup_percent'];
	$merchant_markup_basis    = (string) ( $precheck['merchant_markup_basis'] ?? 'acquirer_cost' );
	$rub_invoice_markup_mode  = (string) ( $precheck['rub_invoice_markup_mode'] ?? 'none' );
	$merchant_name            = trim( (string) ( $merchant->name ?? '' ) );
	$merchant_label           = $merchant_name !== '' ? $merchant_name : 'Merchant #' . $merchant_id;
	$custom_payment_purpose   = crm_fintech_normalize_payment_purpose( $args['payment_purpose'] ?? '' );
	$default_payment_purpose  = crm_fintech_get_pay2day_default_payment_purpose( $company_id );
	$payment_purpose          = $custom_payment_purpose !== '' ? $custom_payment_purpose : $default_payment_purpose;
	$payment_purpose_source   = $custom_payment_purpose !== ''
		? 'custom'
		: ( $default_payment_purpose !== '' ? 'default' : 'automatic' );
	$rapira                   = rates_get_rapira();

	if ( empty( $rapira['ok'] ) || empty( $rapira['ask'] ) || (float) $rapira['ask'] <= 0 ) {
		$result['error_code'] = 'provider_unavailable';
		$error_message   = 'Не удалось получить живой курс Rapira USDT/RUB.';
		$error_message  .= ! empty( $rapira['error'] ) ? ' ' . (string) $rapira['error'] : '';

		crm_log( 'merchant.invoice.rapira_failed', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'create',
			'message'     => 'Не удалось получить Rapira ask для merchant RUB invoice.',
			'target_type' => 'merchant',
			'target_id'   => $merchant_id,
			'org_id'      => $company_id,
			'is_success'  => false,
			'context'     => [
				'requested_rub' => $requested_rub,
				'rapira_error'  => (string) ( $rapira['error'] ?? '' ),
			],
		] );

		$result['error'] = $error_message;
		return $result;
	}

	$economics                  = crm_merchant_calculate_rub_invoice_economics(
		(float) $rapira['ask'],
		$acquirer_markup_percent,
		$merchant_markup_percent,
		$merchant_markup_basis,
		$requested_rub,
		$rub_invoice_markup_mode
	);
	$requested_rub_input        = (float) $economics['requested_rub_input'];
	$payment_amount_rub         = (float) $economics['payment_amount_rub'];
	$merchant_markup_added_rub  = (float) $economics['merchant_markup_added_rub'];
	$rapira_ask                 = (float) $economics['rapira_ask'];
	$acquirer_rate              = (float) $economics['acquirer_rate_raw'];
	$merchant_markup_basis      = (string) ( $economics['merchant_markup_basis'] ?? $merchant_markup_basis );
	$rub_invoice_markup_mode    = (string) ( $economics['rub_invoice_markup_mode'] ?? $rub_invoice_markup_mode );
	$merchant_rate_raw          = (float) $economics['merchant_rate_raw'];
	$merchant_rate              = (float) $economics['merchant_rate_commercial'];
	$expected_acquirer_gross    = (float) $economics['expected_acquirer_gross'];
	$merchant_payable_usdt      = (float) $economics['merchant_payable_usdt'];

	$result['merchant_markup_basis'] = $merchant_markup_basis;
	$result['rub_invoice_markup_mode'] = $rub_invoice_markup_mode;
	$result['merchant_markup_added_rub'] = $merchant_markup_added_rub;
	$result['rapira_ask']            = $rapira_ask;
	$result['acquirer_rate']         = $acquirer_rate;
	$result['merchant_rate']         = $merchant_rate;
	$result['merchant_payable_usdt'] = $merchant_payable_usdt;
	$result['payment_purpose']       = $payment_purpose;
	$result['payment_amount_rub']    = $payment_amount_rub;

	$description = $payment_purpose !== ''
		? $payment_purpose
		: sprintf(
			'%s merchant RUB invoice %.2f RUB',
			$merchant_label,
			$payment_amount_rub
		);

	Fintech_Payment_Gateway::set_company_id( $company_id );
	$invoice = fintech_create_invoice_by_payment_amount( $payment_amount_rub, 'RUB', null, $description );

	if ( empty( $invoice['success'] ) ) {
		$result['error_code'] = 'provider_error';
		crm_log( 'merchant.invoice.gateway_failed', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'create',
			'message'     => 'Kanyon не создал merchant RUB invoice.',
			'target_type' => 'merchant',
			'target_id'   => $merchant_id,
			'org_id'      => $company_id,
			'is_success'  => false,
			'context'     => [
				'requested_rub' => $requested_rub,
				'payment_amount_rub' => $payment_amount_rub,
				'error'         => (string) ( $invoice['error'] ?? '' ),
				'payment_purpose' => $payment_purpose,
			],
		] );

		$result['error'] = (string) ( $invoice['error'] ?? 'Gateway error' );
		return $result;
	}

	$payload_mode        = (string) ( $invoice['payloadMode'] ?? 'paymentAmount' );
	$kanyon_gross_usdt   = isset( $invoice['amountAssetValue'] ) && $invoice['amountAssetValue'] !== null
		? round( max( 0, (float) $invoice['amountAssetValue'] ), 8 )
		: 0.0;
	$platform_fee_usdt   = round( max( 0, $kanyon_gross_usdt - $merchant_payable_usdt ), 8 );
	$gross_difference    = abs( $expected_acquirer_gross - $kanyon_gross_usdt );

	$result['warning']             = isset( $invoice['warning'] ) ? (string) $invoice['warning'] : null;
	$result['provider']            = (string) ( $invoice['provider'] ?? Fintech_Payment_Gateway::PROVIDER_KANYON );
	$result['merchant_order_id']   = (string) ( $invoice['merchantOrderId'] ?? '' );
	$result['provider_order_id']   = (string) ( $invoice['orderId'] ?? '' );
	$result['status_code']         = function_exists( '_crm_fintech_map_status' )
		? _crm_fintech_map_status( (string) ( $invoice['providerStatus'] ?? 'CREATED' ) )
		: 'created';
	$result['payment_link']        = (string) ( $invoice['payload'] ?? '' );
	$result['qrc_id']              = (string) ( $invoice['qrcId'] ?? '' );
	$result['payment_amount_rub']  = isset( $invoice['paymentAmountValue'] ) && $invoice['paymentAmountValue'] !== null
		? round( (float) $invoice['paymentAmountValue'], 2 )
		: $payment_amount_rub;
	$result['kanyon_gross_usdt']   = $kanyon_gross_usdt;
	$result['platform_fee_usdt']   = $platform_fee_usdt;

	$merchant_meta = [
		'rapira_symbol'                => (string) ( $rapira['symbol'] ?? 'USDT/RUB' ),
		'rapira_bid'                   => isset( $rapira['bid'] ) && $rapira['bid'] !== null ? (float) $rapira['bid'] : null,
		'rapira_ask'                   => $rapira_ask,
		'rapira_close'                 => isset( $rapira['close'] ) && $rapira['close'] !== null ? (float) $rapira['close'] : null,
		'rapira_checked_at'            => current_time( 'mysql', true ),
		'acquirer_markup_percent'      => $acquirer_markup_percent,
		'merchant_markup_type'         => 'percent',
		'merchant_markup_basis'        => $merchant_markup_basis,
		'rub_invoice_markup_mode'      => $rub_invoice_markup_mode,
		'merchant_markup_value'        => $merchant_markup_percent,
		'merchant_requested_rub_input' => $requested_rub_input,
		'payment_amount_rub'           => $payment_amount_rub,
		'merchant_markup_added_rub'    => $merchant_markup_added_rub,
		'acquirer_rate'                => $acquirer_rate,
		'merchant_rate'                => $merchant_rate,
		'merchant_rate_raw'            => $merchant_rate_raw,
		'expected_acquirer_gross_usdt' => $expected_acquirer_gross,
		'kanyon_gross_usdt'            => $kanyon_gross_usdt > 0 ? $kanyon_gross_usdt : null,
		'kanyon_order_amount_raw'      => $invoice['orderAmountCents'] ?? null,
		'kanyon_payment_amount_raw'    => $invoice['paymentAmountMinor'] ?? null,
		'kanyon_payload_mode'          => $payload_mode,
		'payment_purpose'              => $payment_purpose !== '' ? $payment_purpose : null,
		'payment_purpose_source'       => $payment_purpose_source,
	];

	$order_db_id = crm_fintech_save_order(
		$invoice,
		$company_id,
		$source_channel !== '' ? $source_channel : 'telegram_merchant',
		$created_by_user_id,
		[
			'merchant_id'                 => $merchant_id,
			'created_for_type'            => 'merchant',
			'amount_asset_code'           => 'USDT',
			'amount_asset_value'          => number_format( $kanyon_gross_usdt, 8, '.', '' ),
			'payment_currency_code'       => 'RUB',
			'payment_amount_value'        => number_format( $payment_amount_rub, 2, '.', '' ),
			'merchant_requested_rub_value'=> number_format( $requested_rub_input, 2, '.', '' ),
			'merchant_payable_value'      => number_format( $merchant_payable_usdt, 8, '.', '' ),
			'merchant_markup_value'       => number_format( $platform_fee_usdt, 8, '.', '' ),
			'platform_fee_value'          => number_format( $platform_fee_usdt, 8, '.', '' ),
			'merchant_profit_value'       => number_format( 0, 8, '.', '' ),
			'referral_reward_value'       => number_format( 0, 8, '.', '' ),
			'local_order_ref'             => $external_order_id !== '' ? $external_order_id : null,
			'idempotency_key'             => $idempotency_key !== '' ? $idempotency_key : null,
			'meta_json'                   => wp_json_encode(
				[
					'merchant_flow' => 'rub_usdt_rapira_kanyon',
					'merchant_id'   => $merchant_id,
					'merchant_name' => $merchant_label,
					'merchant_markup_basis' => $merchant_markup_basis,
					'rub_invoice_markup_mode' => $rub_invoice_markup_mode,
					'merchant_requested_rub_input' => $requested_rub_input,
					'payment_amount_rub' => $payment_amount_rub,
					'merchant_markup_added_rub' => $merchant_markup_added_rub,
					'external_order_id' => $external_order_id !== '' ? $external_order_id : null,
					'payment_purpose' => $payment_purpose,
					'payment_purpose_source' => $payment_purpose_source,
				],
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
			'merchant_meta_json'          => wp_json_encode(
				$merchant_meta,
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
			'notes'                       => $kanyon_gross_usdt > 0
				? 'Merchant RUB -> USDT invoice created via Rapira + Kanyon paymentAmount flow.'
				: 'Merchant RUB -> USDT invoice created via Rapira + Kanyon paymentAmount flow. Waiting for provider gross orderAmount.',
		]
	);

	if ( $order_db_id === null || $order_db_id <= 0 ) {
		$result['error_code'] = 'internal_error';
		crm_log( 'merchant.invoice.db_save_failed', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'create',
			'message'     => 'Merchant RUB invoice создан у провайдера, но не сохранился локально.',
			'target_type' => 'merchant',
			'target_id'   => $merchant_id,
			'org_id'      => $company_id,
			'is_success'  => false,
			'context'     => [
				'merchant_order_id' => (string) ( $invoice['merchantOrderId'] ?? '' ),
				'provider_order_id' => (string) ( $invoice['orderId'] ?? '' ),
				'requested_rub'     => $requested_rub,
				'payment_amount_rub'=> $payment_amount_rub,
				'external_order_id' => $external_order_id !== '' ? $external_order_id : null,
				'merchant_markup_basis' => $merchant_markup_basis,
				'payment_purpose'   => $payment_purpose,
			],
		] );

		$result['error'] = 'Счёт создан у провайдера, но не сохранился в Malibu.';
		return $result;
	}

	$result['order_db_id'] = $order_db_id;

	if ( $result['payment_link'] !== '' && $result['qrc_id'] !== '' ) {
		$result['qr_url'] = crm_fintech_qr_url(
			$result['payment_link'],
			$result['qrc_id'],
			$result['merchant_order_id'] !== '' ? $result['merchant_order_id'] : (string) $order_db_id
		);
	}

	if ( $gross_difference > 0.01 ) {
		crm_log( 'merchant.invoice.gross_mismatch', [
			'category'    => 'payments',
			'level'       => 'warning',
			'action'      => 'create',
			'message'     => 'Фактический gross Kanyon отличается от ожидаемого расчёта Rapira.',
			'target_type' => 'payment_order',
			'target_id'   => $order_db_id,
			'org_id'      => $company_id,
			'is_success'  => true,
			'context'     => [
				'merchant_id'                   => $merchant_id,
				'requested_rub'                 => $requested_rub,
				'payment_amount_rub'            => $payment_amount_rub,
				'expected_acquirer_gross_usdt'  => $expected_acquirer_gross,
				'kanyon_gross_usdt'             => $kanyon_gross_usdt,
				'difference_usdt'               => $gross_difference,
				'external_order_id'             => $external_order_id !== '' ? $external_order_id : null,
				'merchant_markup_basis'         => $merchant_markup_basis,
				'payment_purpose'               => $payment_purpose,
			],
		] );
	}

	crm_log( 'merchant.invoice.created', [
		'category'    => 'payments',
		'level'       => 'info',
		'action'      => 'create',
		'message'     => 'Создан merchant RUB -> USDT invoice.',
		'target_type' => 'payment_order',
		'target_id'   => $order_db_id,
		'org_id'      => $company_id,
		'is_success'  => true,
		'context'     => [
			'merchant_id'              => $merchant_id,
			'merchant_order_id'        => $result['merchant_order_id'],
			'provider_order_id'        => $result['provider_order_id'],
			'requested_rub'            => $requested_rub,
			'payment_amount_rub'       => $payment_amount_rub,
			'merchant_payable_usdt'    => $merchant_payable_usdt,
			'platform_fee_usdt'        => $platform_fee_usdt,
			'kanyon_gross_usdt'        => $kanyon_gross_usdt,
			'external_order_id'        => $external_order_id !== '' ? $external_order_id : null,
			'rapira_ask'               => $rapira_ask,
			'acquirer_rate'            => $acquirer_rate,
			'merchant_rate'            => $merchant_rate,
			'merchant_rate_raw'        => $merchant_rate_raw,
			'merchant_markup_basis'    => $merchant_markup_basis,
			'rub_invoice_markup_mode'  => $rub_invoice_markup_mode,
			'merchant_markup_added_rub'=> $merchant_markup_added_rub,
			'payload_mode'             => $payload_mode,
			'payment_purpose'          => $payment_purpose,
			'payment_purpose_source'   => $payment_purpose_source,
		],
	] );

	$result['success'] = true;
	$result['error_code'] = null;
	$result['error']   = null;

	return $result;
}

/**
 * Нормализует platform_fee из economics-колонок ордера.
 *
 * @param object|array<string,mixed> $order
 */
function crm_merchant_order_platform_fee_amount( $order ): float {
	$row = is_object( $order ) ? get_object_vars( $order ) : (array) $order;

	if ( array_key_exists( 'platform_fee_value', $row ) && $row['platform_fee_value'] !== null && $row['platform_fee_value'] !== '' ) {
		return max( 0, round( (float) $row['platform_fee_value'], 8 ) );
	}

	if ( array_key_exists( 'merchant_markup_value', $row ) && $row['merchant_markup_value'] !== null && $row['merchant_markup_value'] !== '' ) {
		return max( 0, round( (float) $row['merchant_markup_value'], 8 ) );
	}

	return 0.0;
}

/**
 * Возвращает сумму USDT, которую платформа должна выплатить мерчанту по ордеру.
 *
 * @param object|array<string,mixed> $order
 */
function crm_merchant_order_payable_amount( $order ): float {
	$row = is_object( $order ) ? get_object_vars( $order ) : (array) $order;

	if ( array_key_exists( 'merchant_payable_value', $row ) && $row['merchant_payable_value'] !== null && $row['merchant_payable_value'] !== '' ) {
		return max( 0, round( (float) $row['merchant_payable_value'], 8 ) );
	}

	$gross_amount   = isset( $row['amount_asset_value'] ) ? (float) $row['amount_asset_value'] : 0.0;
	$platform_fee   = crm_merchant_order_platform_fee_amount( $row );
	$referral_value = ( isset( $row['referral_reward_value'] ) && $row['referral_reward_value'] !== null && $row['referral_reward_value'] !== '' )
		? (float) $row['referral_reward_value']
		: 0.0;

	return max( 0, round( $gross_amount - $platform_fee - $referral_value, 8 ) );
}

/**
 * Создаёт ledger-начисление по merchant order, который перешёл в paid.
 *
 * @return array<string,mixed>
 */
function crm_merchant_create_paid_order_accrual( int $payment_order_id, string $source_code = 'system' ): array {
	global $wpdb;

	$result = [
		'status'     => 'skipped',
		'reason'     => 'invalid_order',
		'ledger_id'  => 0,
		'company_id' => 0,
		'merchant_id'=> 0,
		'amount'     => 0.0,
	];

	if ( $payment_order_id <= 0 ) {
		return $result;
	}

	$order = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, company_id, merchant_id, created_for_type, status_code, merchant_order_id,
			        payment_currency_code, payment_amount_value, amount_asset_code, amount_asset_value,
			        merchant_requested_rub_value, merchant_payable_value, merchant_meta_json,
			        merchant_markup_value, platform_fee_value, merchant_profit_value, referral_reward_value
			 FROM crm_fintech_payment_orders
			 WHERE id = %d
			 LIMIT 1",
			$payment_order_id
		)
	);

	if ( ! $order ) {
		return $result;
	}

	$company_id  = (int) $order->company_id;
	$merchant_id = (int) $order->merchant_id;
	$status_code = (string) $order->status_code;
	$result['company_id']  = $company_id;
	$result['merchant_id'] = $merchant_id;

	if ( $company_id <= 0 ) {
		$result['reason'] = 'invalid_company';
		crm_log( 'merchant.accrual_skipped', [
			'category'    => 'payments',
			'level'       => 'warning',
			'action'      => 'accrual',
			'message'     => 'Начисление мерчанту пропущено: ордер без валидной компании.',
			'target_type' => 'payment_order',
			'target_id'   => $payment_order_id,
			'is_success'  => false,
			'context'     => [
				'company_id'  => $company_id,
				'merchant_id' => $merchant_id,
				'source_code' => $source_code,
			],
		] );
		return $result;
	}

	if ( (string) $order->created_for_type !== 'merchant' ) {
		$result['reason'] = 'not_merchant_order';
		return $result;
	}

	if ( $merchant_id <= 0 ) {
		$result['reason'] = 'missing_merchant_id';
		crm_log( 'merchant.accrual_skipped', [
			'category'    => 'payments',
			'level'       => 'warning',
			'action'      => 'accrual',
			'message'     => 'Начисление мерчанту пропущено: merchant_id отсутствует у merchant order.',
			'target_type' => 'payment_order',
			'target_id'   => $payment_order_id,
			'org_id'      => $company_id,
			'is_success'  => false,
			'context'     => [
				'merchant_order_id' => (string) $order->merchant_order_id,
				'source_code'       => $source_code,
			],
		] );
		return $result;
	}

	if ( $status_code !== 'paid' ) {
		$result['reason'] = 'order_not_paid';
		return $result;
	}

	$merchant_company_id = crm_get_merchant_company_id( $merchant_id );
	if ( $merchant_company_id <= 0 || $merchant_company_id !== $company_id ) {
		$result['reason'] = 'merchant_scope_mismatch';
		crm_log( 'merchant.accrual_scope_mismatch', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'accrual',
			'message'     => 'Начисление мерчанту заблокировано: company scope у ордера и мерчанта не совпадает.',
			'target_type' => 'payment_order',
			'target_id'   => $payment_order_id,
			'org_id'      => $company_id,
			'is_success'  => false,
			'context'     => [
				'merchant_id'          => $merchant_id,
				'order_company_id'     => $company_id,
				'merchant_company_id'  => $merchant_company_id,
				'merchant_order_id'    => (string) $order->merchant_order_id,
				'source_code'          => $source_code,
			],
		] );
		return $result;
	}

	$gross_amount = isset( $order->amount_asset_value ) ? round( (float) $order->amount_asset_value, 8 ) : 0.0;
	if ( $gross_amount <= 0 ) {
		$result['reason'] = 'missing_gross_amount';
		crm_log( 'merchant.accrual_blocked_missing_gross', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'accrual',
			'message'     => 'Начисление мерчанту заблокировано: paid order не содержит gross amount от провайдера.',
			'target_type' => 'payment_order',
			'target_id'   => $payment_order_id,
			'org_id'      => $company_id,
			'is_success'  => false,
			'context'     => [
				'merchant_id'          => $merchant_id,
				'merchant_order_id'    => (string) $order->merchant_order_id,
				'payment_currency_code'=> (string) ( $order->payment_currency_code ?? '' ),
				'payment_amount_value' => $order->payment_amount_value !== null ? (float) $order->payment_amount_value : null,
				'amount_asset_value'   => $gross_amount,
				'source_code'          => $source_code,
			],
		] );
		return $result;
	}

	$payable_amount = crm_merchant_order_payable_amount( $order );
	$result['amount'] = $payable_amount;

	if ( $payable_amount <= 0 ) {
		$result['reason'] = 'empty_payable';
		crm_log( 'merchant.accrual_skipped', [
			'category'    => 'payments',
			'level'       => 'warning',
			'action'      => 'accrual',
			'message'     => 'Начисление мерчанту пропущено: payable amount равен нулю.',
			'target_type' => 'payment_order',
			'target_id'   => $payment_order_id,
			'org_id'      => $company_id,
			'is_success'  => false,
			'context'     => [
				'merchant_id'        => $merchant_id,
				'merchant_order_id'  => (string) $order->merchant_order_id,
				'amount_asset_value' => (float) $order->amount_asset_value,
				'source_code'        => $source_code,
			],
		] );
		return $result;
	}

	$existing_ledger_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT id FROM crm_merchant_wallet_ledger WHERE source_order_id = %d AND merchant_id = %d AND entry_type = %s LIMIT 1',
			$payment_order_id,
			$merchant_id,
			CRM_MERCHANT_WALLET_ENTRY_MERCHANT_ACCRUAL
		)
	);

	if ( $existing_ledger_id > 0 ) {
		$result['status']    = 'duplicate';
		$result['reason']    = 'already_accrued';
		$result['ledger_id'] = $existing_ledger_id;
		return $result;
	}

	$order_updates = [];
	if ( $order->merchant_payable_value === null || $order->merchant_payable_value === '' ) {
		$order_updates['merchant_payable_value'] = number_format( $payable_amount, 8, '.', '' );
	}

	$platform_fee_amount = crm_merchant_order_platform_fee_amount( $order );
	if ( ( $order->platform_fee_value === null || $order->platform_fee_value === '' ) && $platform_fee_amount > 0 ) {
		$order_updates['platform_fee_value'] = number_format( $platform_fee_amount, 8, '.', '' );
	}

	if ( $order->merchant_profit_value === null || $order->merchant_profit_value === '' ) {
		$order_updates['merchant_profit_value'] = number_format( 0, 8, '.', '' );
	}

	if ( ! empty( $order_updates ) ) {
		$wpdb->update(
			'crm_fintech_payment_orders',
			$order_updates,
			[ 'id' => $payment_order_id ]
		);
	}

	$row_data = [
		'company_id'      => $company_id,
		'merchant_id'     => $merchant_id,
		'entry_type'      => CRM_MERCHANT_WALLET_ENTRY_MERCHANT_ACCRUAL,
		'amount'          => number_format( $payable_amount, 8, '.', '' ),
		'currency_code'   => strtoupper( (string) ( $order->amount_asset_code ?: 'USDT' ) ),
		'source_order_id' => $payment_order_id,
		'comment'         => sprintf(
			'Начисление по оплаченному order %s',
			(string) ( $order->merchant_order_id ?: ( '#' . $payment_order_id ) )
		),
		'created_at'      => current_time( 'mysql' ),
	];

	$row_formats = [ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ];

	$current_user_id = get_current_user_id();
	if ( $current_user_id > 0 ) {
		$row_data['created_by_user_id'] = $current_user_id;
		$row_formats[]                  = '%d';
	}

	$inserted = $wpdb->insert( 'crm_merchant_wallet_ledger', $row_data, $row_formats );
	if ( $inserted === false ) {
		$last_error = strtolower( (string) $wpdb->last_error );
		if ( strpos( $last_error, 'duplicate' ) !== false ) {
			$result['status']    = 'duplicate';
			$result['reason']    = 'already_accrued';
			$result['ledger_id'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM crm_merchant_wallet_ledger WHERE source_order_id = %d AND merchant_id = %d AND entry_type = %s LIMIT 1',
					$payment_order_id,
					$merchant_id,
					CRM_MERCHANT_WALLET_ENTRY_MERCHANT_ACCRUAL
				)
			);
			return $result;
		}

		$result['reason'] = 'insert_failed';
		crm_log( 'merchant.accrual_failed', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'accrual',
			'message'     => 'Не удалось записать merchant accrual в ledger.',
			'target_type' => 'payment_order',
			'target_id'   => $payment_order_id,
			'org_id'      => $company_id,
			'is_success'  => false,
			'context'     => [
				'merchant_id'       => $merchant_id,
				'merchant_order_id' => (string) $order->merchant_order_id,
				'amount'            => $payable_amount,
				'source_code'       => $source_code,
				'db_error'          => (string) $wpdb->last_error,
			],
		] );
		return $result;
	}

	$result['status']    = 'created';
	$result['reason']    = 'created';
	$result['ledger_id'] = (int) $wpdb->insert_id;

	crm_log( 'merchant.accrual_created', [
		'category'    => 'payments',
		'level'       => 'info',
		'action'      => 'accrual',
		'message'     => 'Создано начисление мерчанту по paid order.',
		'target_type' => 'merchant_ledger_entry',
		'target_id'   => $result['ledger_id'],
		'org_id'      => $company_id,
		'is_success'  => true,
		'context'     => [
			'payment_order_id'    => $payment_order_id,
			'merchant_id'         => $merchant_id,
			'merchant_order_id'   => (string) $order->merchant_order_id,
			'merchant_payable'    => $payable_amount,
			'platform_fee_value'  => $platform_fee_amount,
			'source_code'         => $source_code,
		],
	] );

	return $result;
}

/**
 * Синхронизирует явную referral-связь в crm_merchant_referrals.
 */
function crm_sync_merchant_referral_link( int $company_id, int $merchant_id, int $referred_by_merchant_id = 0 ): bool {
	global $wpdb;

	if ( $company_id <= 0 || $merchant_id <= 0 ) {
		return false;
	}

	$wpdb->delete(
		'crm_merchant_referrals',
		[
			'company_id'           => $company_id,
			'referral_merchant_id' => $merchant_id,
		],
		[ '%d', '%d' ]
	);

	if ( $referred_by_merchant_id <= 0 ) {
		return true;
	}

	$wpdb->insert(
		'crm_merchant_referrals',
		[
			'company_id'           => $company_id,
			'referrer_merchant_id' => $referred_by_merchant_id,
			'referral_merchant_id' => $merchant_id,
			'created_at'           => current_time( 'mysql' ),
		],
		[ '%d', '%d', '%d', '%s' ]
	);

	return $wpdb->last_error === '';
}

/**
 * Создаёт криптографически случайный invite_token.
 */
function crm_generate_merchant_invite_token(): string {
	global $wpdb;

	do {
		$token = bin2hex( random_bytes( 24 ) );
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM crm_merchant_invites WHERE invite_token = %s LIMIT 1',
				$token
			)
		);
	} while ( $exists > 0 );

	return $token;
}

/**
 * Создаёт короткий Telegram-safe payload для deep-link start.
 */
function crm_generate_merchant_invite_start_payload(): string {
	global $wpdb;

	do {
		$payload = bin2hex( random_bytes( 16 ) );
		$exists  = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM crm_merchant_invites WHERE telegram_start_payload = %s LIMIT 1',
				$payload
			)
		);
	} while ( $exists > 0 );

	return $payload;
}

/**
 * Telegram deep-link invite URL.
 */
function crm_build_merchant_invite_url( string $bot_username, string $start_payload ): string {
	$bot_username  = crm_telegram_sanitize_bot_username( $bot_username );
	$start_payload = trim( $start_payload );

	if ( $bot_username === '' || $start_payload === '' ) {
		return '';
	}

	return 'https://t.me/' . rawurlencode( $bot_username ) . '?start=' . rawurlencode( $start_payload );
}

/**
 * Унифицированно строит ссылку invite из строки БД.
 *
 * @param array<string,mixed>|object $invite
 */
function crm_build_merchant_invite_url_from_row( $invite ): string {
	$row = is_object( $invite ) ? get_object_vars( $invite ) : (array) $invite;

	$company_id = isset( $row['company_id'] ) ? (int) $row['company_id'] : 0;
	$bot_username = trim( (string) ( $row['bot_username_snapshot'] ?? '' ) );
	if ( $bot_username === '' && $company_id > 0 ) {
		$bot_username = crm_telegram_collect_settings( $company_id )['bot_username'] ?? '';
	}

	return crm_build_merchant_invite_url(
		$bot_username,
		(string) ( $row['telegram_start_payload'] ?? '' )
	);
}

/**
 * Подключает библиотеку генерации QR-кодов.
 */
function crm_merchant_require_qrcode_lib(): bool {
	if ( class_exists( 'QRcode' ) ) {
		return true;
	}

	$paths = [
		get_template_directory() . '/vendorsphp/QR/phpqrcode/qrlib.php',
	];

	foreach ( $paths as $path ) {
		if ( is_file( $path ) ) {
			require_once $path;
			if ( class_exists( 'QRcode' ) ) {
				return true;
			}
		}
	}

	return class_exists( 'QRcode' );
}

/**
 * Генерирует QR PNG для Telegram invite и возвращает URL до файла.
 */
function crm_get_merchant_invite_qr_url( string $invite_url, int $company_id, string $start_payload ): string {
	$invite_url    = trim( $invite_url );
	$start_payload = preg_replace( '/[^A-Za-z0-9_-]/', '', $start_payload );

	if ( $invite_url === '' || $company_id <= 0 || $start_payload === '' ) {
		return '';
	}

	if ( ! crm_merchant_require_qrcode_lib() ) {
		return '';
	}

	$dir = trailingslashit( get_template_directory() ) . 'uploadbotfiles/merchant-invites/';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$file_name = 'tg_invite_' . $company_id . '_' . $start_payload . '.png';
	$abs_path  = $dir . $file_name;
	if ( ! is_file( $abs_path ) ) {
		QRcode::png( $invite_url, $abs_path, QR_ECLEVEL_M, 8, 1 );
	}

	return trailingslashit( get_template_directory_uri() ) . 'uploadbotfiles/merchant-invites/' . rawurlencode( $file_name );
}

/**
 * Возвращает локальный avatar URL мерчанта или пустую строку.
 *
 * @param array<string,mixed>|object $merchant
 */
function crm_get_merchant_avatar_url( $merchant ): string {
	$row = is_object( $merchant ) ? get_object_vars( $merchant ) : (array) $merchant;

	return trim( (string) ( $row['telegram_avatar_url'] ?? '' ) );
}

/**
 * Автоматически переводит просроченные инвайты в expired.
 */
function crm_expire_merchant_invites( int $company_id = 0 ): int {
	global $wpdb;

	$where  = "WHERE status = 'new' AND expires_at IS NOT NULL AND expires_at <= %s";
	$params = [ current_time( 'mysql', true ) ];

	if ( $company_id > 0 ) {
		$where   .= ' AND company_id = %d';
		$params[] = $company_id;
	}

	$sql = "UPDATE crm_merchant_invites SET status = 'expired' {$where}";

	return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) );
}

/**
 * Карточка-бейдж статуса мерчанта.
 */
function crm_merchant_status_badge_class( string $status ): string {
	if ( $status === CRM_MERCHANT_STATUS_BLOCKED ) {
		return 'danger';
	}
	if ( $status === CRM_MERCHANT_STATUS_ARCHIVED ) {
		return 'secondary';
	}
	if ( $status === CRM_MERCHANT_STATUS_PENDING ) {
		return 'warning';
	}

	return 'success';
}

/**
 * Бейдж статуса invite.
 */
function crm_merchant_invite_badge_class( string $status ): string {
	if ( $status === 'used' ) {
		return 'success';
	}
	if ( $status === 'expired' ) {
		return 'warning';
	}
	if ( $status === 'revoked' ) {
		return 'secondary';
	}

	return 'primary';
}

/**
 * Короткий label для markup_type.
 */
function crm_merchant_markup_type_label( string $type ): string {
	$types = crm_merchant_markup_types();

	return $types[ $type ] ?? $types['percent'];
}

/**
 * Короткий label для ledger entry type.
 */
function crm_merchant_wallet_entry_type_label( string $type ): string {
	$types = crm_merchant_wallet_entry_types();

	return $types[ $type ] ?? $type;
}

/**
 * Форматирует суммы для UI.
 */
function crm_merchant_format_amount( $amount, string $currency_code = 'USDT' ): string {
	$number = number_format( (float) $amount, 8, '.', '' );
	$number = rtrim( rtrim( $number, '0' ), '.' );
	if ( $number === '' || $number === '-0' ) {
		$number = '0';
	}

	return $number . ' ' . strtoupper( $currency_code );
}

/**
 * Агрегаты кошелька по набору merchant_id.
 *
 * @param int[] $merchant_ids
 * @return array<int,array<string,float>>
 */
function crm_get_merchant_balance_summary_map( array $merchant_ids ): array {
	global $wpdb;

	$merchant_ids = array_values( array_filter( array_map( 'intval', $merchant_ids ) ) );
	if ( empty( $merchant_ids ) ) {
		return [];
	}

	$sql_ids = implode( ',', $merchant_ids );
	$rows    = $wpdb->get_results(
		"SELECT merchant_id,
		        SUM(CASE
		                WHEN entry_type IN ('merchant_accrual','manual_credit','merchant_payout_reversal')
		                  THEN amount
		                WHEN entry_type IN ('manual_debit','merchant_payout')
		                  THEN -ABS(amount)
		                ELSE 0
		            END) AS main_balance,
		        SUM(CASE
		                WHEN entry_type IN ('bonus_accrual','bonus_adjustment')
		                  THEN amount
		                WHEN entry_type = 'bonus_withdrawal'
		                  THEN -ABS(amount)
		                ELSE 0
		            END) AS bonus_balance,
		        SUM(CASE
		                WHEN entry_type IN ('referral_accrual','referral_adjustment')
		                  THEN amount
		                ELSE 0
		            END) AS referral_balance,
		        SUM(CASE
		                WHEN entry_type IN ('merchant_accrual','manual_credit','merchant_payout_reversal','bonus_accrual','bonus_adjustment','referral_accrual','referral_adjustment')
		                  THEN amount
		                WHEN entry_type IN ('manual_debit','merchant_payout','bonus_withdrawal')
		                  THEN -ABS(amount)
		                ELSE 0
		            END) AS total_balance
		 FROM crm_merchant_wallet_ledger
		 WHERE merchant_id IN ($sql_ids)
		 GROUP BY merchant_id",
		ARRAY_A
	) ?: [];

	$map = [];
	foreach ( $rows as $row ) {
		$merchant_id = (int) $row['merchant_id'];
		$map[ $merchant_id ] = [
			'main_balance'     => (float) ( $row['main_balance'] ?? 0 ),
			'bonus_balance'    => (float) ( $row['bonus_balance'] ?? 0 ),
			'referral_balance' => (float) ( $row['referral_balance'] ?? 0 ),
			'total_balance'    => (float) ( $row['total_balance'] ?? 0 ),
		];
	}

	return $map;
}

/**
 * Возвращает payout-запись мерчанта в рамках компании.
 */
function crm_merchant_get_payout( int $company_id, int $payout_id ): ?object {
	global $wpdb;

	if ( $company_id <= 0 || $payout_id <= 0 ) {
		return null;
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT *
			 FROM crm_merchant_payouts
			 WHERE id = %d
			   AND company_id = %d
			 LIMIT 1",
			$payout_id,
			$company_id
		)
	);

	return $row ?: null;
}

/**
 * Возвращает payout-запись конкретного мерчанта в рамках компании.
 */
function crm_merchant_get_payout_for_merchant( int $company_id, int $merchant_id, int $payout_id ): ?object {
	$payout = crm_merchant_get_payout( $company_id, $payout_id );
	if ( ! $payout || (int) ( $payout->merchant_id ?? 0 ) !== $merchant_id ) {
		return null;
	}

	return $payout;
}

/**
 * Подтверждает payout по кнопке мерчанта.
 *
 * @return array<string,mixed>
 */
function crm_merchant_confirm_payout( int $company_id, int $merchant_id, int $payout_id ): array {
	global $wpdb;

	$payout = crm_merchant_get_payout_for_merchant( $company_id, $merchant_id, $payout_id );
	if ( ! $payout ) {
		return [
			'success' => false,
			'code'    => 'not_found',
			'error'   => 'Выплата не найдена.',
		];
	}

	$status = crm_merchant_normalize_payout_status( (string) ( $payout->status_code ?? '' ) );
	if ( $status === CRM_MERCHANT_PAYOUT_STATUS_CONFIRMED ) {
		return [
			'success' => true,
			'code'    => 'already_confirmed',
			'message' => 'Подтверждение уже было получено.',
			'payout'  => $payout,
		];
	}
	if ( $status === CRM_MERCHANT_PAYOUT_STATUS_CANCELLED ) {
		return [
			'success' => false,
			'code'    => 'cancelled',
			'error'   => 'Эта выплата была отменена.',
			'payout'  => $payout,
		];
	}

	$now = current_time( 'mysql' );
	$updated = $wpdb->update(
		'crm_merchant_payouts',
		[
			'status_code'  => CRM_MERCHANT_PAYOUT_STATUS_CONFIRMED,
			'confirmed_at' => $now,
			'updated_at'   => $now,
		],
		[
			'id'         => $payout_id,
			'company_id' => $company_id,
			'merchant_id'=> $merchant_id,
			'status_code'=> CRM_MERCHANT_PAYOUT_STATUS_PAID,
		],
		[ '%s', '%s', '%s' ],
		[ '%d', '%d', '%d', '%s' ]
	);

	if ( $updated === false ) {
		return [
			'success' => false,
			'code'    => 'db_error',
			'error'   => 'Не удалось зафиксировать подтверждение выплаты.',
		];
	}
	if ( $updated === 0 ) {
		$actual = crm_merchant_get_payout_for_merchant( $company_id, $merchant_id, $payout_id );
		if ( ! $actual ) {
			return [
				'success' => false,
				'code'    => 'state_conflict',
				'error'   => 'Не удалось зафиксировать подтверждение: статус выплаты уже изменился.',
			];
		}
		$actual_status = crm_merchant_normalize_payout_status( (string) ( $actual->status_code ?? '' ) );

		if ( $actual_status === CRM_MERCHANT_PAYOUT_STATUS_CONFIRMED ) {
			return [
				'success' => true,
				'code'    => 'already_confirmed',
				'message' => 'Подтверждение уже было получено.',
				'payout'  => $actual,
			];
		}
		if ( $actual_status === CRM_MERCHANT_PAYOUT_STATUS_CANCELLED ) {
			return [
				'success' => false,
				'code'    => 'cancelled',
				'error'   => 'Эта выплата была отменена.',
				'payout'  => $actual,
			];
		}

		return [
			'success' => false,
			'code'    => 'state_conflict',
			'error'   => 'Не удалось зафиксировать подтверждение: статус выплаты уже изменился.',
			'payout'  => $actual,
		];
	}

	$confirmed = crm_merchant_get_payout_for_merchant( $company_id, $merchant_id, $payout_id );

	return [
		'success' => true,
		'code'    => 'confirmed',
		'message' => 'Спасибо, подтверждение получено.',
		'payout'  => $confirmed ?: $payout,
	];
}

/**
 * Отменяет payout компании и возвращает сумму на баланс мерчанта.
 *
 * @return array<string,mixed>
 */
function crm_merchant_cancel_payout( int $company_id, int $payout_id, int $user_id, string $reason_code, string $comment ): array {
	global $wpdb;

	if ( $company_id <= 0 || $payout_id <= 0 || $user_id <= 0 ) {
		return [
			'success' => false,
			'code'    => 'invalid_context',
			'error'   => 'Недостаточно данных для отмены выплаты.',
		];
	}

	$payout = crm_merchant_get_payout( $company_id, $payout_id );
	if ( ! $payout ) {
		return [
			'success' => false,
			'code'    => 'not_found',
			'error'   => 'Выплата не найдена в текущей компании.',
		];
	}

	$status = crm_merchant_normalize_payout_status( (string) ( $payout->status_code ?? '' ) );
	if ( $status === CRM_MERCHANT_PAYOUT_STATUS_CANCELLED ) {
		return [
			'success' => true,
			'code'    => 'already_cancelled',
			'message' => 'Эта выплата уже отменена.',
			'payout'  => $payout,
		];
	}
	if ( $status === CRM_MERCHANT_PAYOUT_STATUS_CONFIRMED ) {
		return [
			'success' => false,
			'code'    => 'already_confirmed',
			'error'   => 'Подтверждённую выплату отменять нельзя.',
			'payout'  => $payout,
		];
	}

	$reason_code = crm_merchant_normalize_payout_cancellation_reason( $reason_code );
	$comment     = trim( sanitize_textarea_field( $comment ) );
	if ( $comment === '' ) {
		return [
			'success' => false,
			'code'    => 'empty_comment',
			'error'   => 'Для отмены выплаты обязателен комментарий.',
		];
	}

	$now        = current_time( 'mysql' );
	$merchant_id = (int) $payout->merchant_id;
	$amount      = round( (float) ( $payout->amount ?? 0 ), 8 );
	$currency    = strtoupper( trim( (string) ( $payout->currency_code ?? 'USDT' ) ) );

	$reason_label = crm_merchant_payout_cancellation_reason_label( $reason_code );
	$ledger_comment_parts = [
		'Отмена выплаты мерчанту #' . $payout_id,
		'причина: ' . $reason_label,
		$comment,
	];

	$wpdb->query( 'START TRANSACTION' );

	$updated = $wpdb->update(
		'crm_merchant_payouts',
		[
			'status_code'              => CRM_MERCHANT_PAYOUT_STATUS_CANCELLED,
			'cancelled_at'             => $now,
			'cancelled_by_user_id'     => $user_id,
			'cancellation_reason_code' => $reason_code,
			'cancellation_comment'     => $comment,
			'updated_at'               => $now,
		],
		[
			'id'         => $payout_id,
			'company_id' => $company_id,
			'status_code'=> CRM_MERCHANT_PAYOUT_STATUS_PAID,
		],
		[ '%s', '%s', '%d', '%s', '%s', '%s' ],
		[ '%d', '%d', '%s' ]
	);

	if ( $updated === false ) {
		$wpdb->query( 'ROLLBACK' );
		return [
			'success' => false,
			'code'    => 'update_failed',
			'error'   => 'Не удалось обновить статус выплаты.',
		];
	}
	if ( $updated === 0 ) {
		$wpdb->query( 'ROLLBACK' );
		$actual = crm_merchant_get_payout( $company_id, $payout_id );
		if ( ! $actual ) {
			return [
				'success' => false,
				'code'    => 'state_conflict',
				'error'   => 'Не удалось отменить выплату: её статус уже изменился.',
			];
		}
		$actual_status = crm_merchant_normalize_payout_status( (string) ( $actual->status_code ?? '' ) );

		if ( $actual_status === CRM_MERCHANT_PAYOUT_STATUS_CONFIRMED ) {
			return [
				'success' => false,
				'code'    => 'already_confirmed',
				'error'   => 'Подтверждённую выплату отменять нельзя.',
				'payout'  => $actual,
			];
		}
		if ( $actual_status === CRM_MERCHANT_PAYOUT_STATUS_CANCELLED ) {
			return [
				'success' => true,
				'code'    => 'already_cancelled',
				'message' => 'Эта выплата уже отменена.',
				'payout'  => $actual,
			];
		}

		return [
			'success' => false,
			'code'    => 'state_conflict',
			'error'   => 'Не удалось отменить выплату: её статус уже изменился.',
			'payout'  => $actual,
		];
	}

	$ledger_inserted = $wpdb->insert(
		'crm_merchant_wallet_ledger',
		[
			'company_id'         => $company_id,
			'merchant_id'        => $merchant_id,
			'entry_type'         => CRM_MERCHANT_WALLET_ENTRY_MERCHANT_PAYOUT_REVERSAL,
			'amount'             => number_format( $amount, 8, '.', '' ),
			'currency_code'      => $currency !== '' ? $currency : 'USDT',
			'source_payout_id'   => $payout_id,
			'comment'            => implode( '; ', $ledger_comment_parts ),
			'created_by_user_id' => $user_id,
			'created_at'         => $now,
		],
		[ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ]
	);

	if ( $ledger_inserted === false ) {
		$wpdb->query( 'ROLLBACK' );
		return [
			'success' => false,
			'code'    => 'ledger_failed',
			'error'   => 'Не удалось вернуть сумму на баланс мерчанта.',
		];
	}

	$ledger_id = (int) $wpdb->insert_id;
	$wpdb->query( 'COMMIT' );

	$cancelled = crm_merchant_get_payout( $company_id, $payout_id );

	return [
		'success'   => true,
		'code'      => 'cancelled',
		'message'   => 'Выплата отменена, сумма возвращена на баланс мерчанта.',
		'payout'    => $cancelled ?: $payout,
		'ledger_id' => $ledger_id,
	];
}

/**
 * Только root может физически удалять мерчантов.
 */
function crm_merchants_can_hard_delete(): bool {
	return is_user_logged_in() && crm_is_root( get_current_user_id() );
}

/**
 * Возвращает список связанных сущностей, блокирующих hard-delete мерчанта.
 *
 * @return array<int,array<string,mixed>>
 */
function crm_get_merchant_delete_blockers( int $merchant_id ): array {
	global $wpdb;

	if ( $merchant_id <= 0 ) {
		return [];
	}

	$blockers = [];
	$orders_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM crm_fintech_payment_orders WHERE merchant_id = %d',
			$merchant_id
		)
	);
	if ( $orders_count > 0 ) {
		$blockers[] = [
			'code'  => 'orders',
			'label' => 'платёжные ордера',
			'count' => $orders_count,
		];
	}

	$ledger_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM crm_merchant_wallet_ledger WHERE merchant_id = %d OR source_merchant_id = %d',
			$merchant_id,
			$merchant_id
		)
	);
	if ( $ledger_count > 0 ) {
		$blockers[] = [
			'code'  => 'ledger',
			'label' => 'операции ledger',
			'count' => $ledger_count,
		];
	}

	$outgoing_referrals_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM crm_merchant_referrals WHERE referrer_merchant_id = %d',
			$merchant_id
		)
	);
	$child_merchants_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM crm_merchants WHERE referred_by_merchant_id = %d',
			$merchant_id
		)
	);
	$downline_count = max( $outgoing_referrals_count, $child_merchants_count );
	if ( $downline_count > 0 ) {
		$blockers[] = [
			'code'  => 'downline',
			'label' => 'другие мерчанты, привязанные к нему как к пригласителю',
			'count' => $downline_count,
		];
	}

	return $blockers;
}

/**
 * Человекочитаемое описание блокеров удаления.
 */
function crm_describe_merchant_delete_blockers( array $blockers ): string {
	if ( empty( $blockers ) ) {
		return '';
	}

	$parts = [];
	foreach ( $blockers as $blocker ) {
		$label = trim( (string) ( $blocker['label'] ?? '' ) );
		$count = (int) ( $blocker['count'] ?? 0 );
		if ( $label === '' || $count <= 0 ) {
			continue;
		}

		$parts[] = $label . ' (' . $count . ')';
	}

	if ( empty( $parts ) ) {
		return '';
	}

	return 'Удаление запрещено: у мерчанта есть связанные данные: ' . implode( ', ', $parts ) . '.';
}

/**
 * Hard-delete мерчанта только при полном отсутствии бизнес-хвостов.
 *
 * @return array<string,mixed>|WP_Error
 */
function crm_hard_delete_merchant( int $merchant_id, int $actor_user_id = 0 ) {
	global $wpdb;

	if ( $merchant_id <= 0 ) {
		return new WP_Error( 'merchant_invalid_id', 'Неверный ID мерчанта.' );
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT id, company_id, name, chat_id, telegram_avatar_path FROM crm_merchants WHERE id = %d LIMIT 1',
			$merchant_id
		)
	);
	if ( ! $row ) {
		return new WP_Error( 'merchant_not_found', 'Мерчант не найден.' );
	}

	$blockers = crm_get_merchant_delete_blockers( $merchant_id );
	if ( ! empty( $blockers ) ) {
		return new WP_Error(
			'merchant_has_history',
			crm_describe_merchant_delete_blockers( $blockers ),
			[ 'blockers' => $blockers ]
		);
	}

	$wpdb->update(
		'crm_merchants',
		[ 'invited_via_invite_id' => null ],
		[ 'id' => $merchant_id ],
		[ '%d' ],
		[ '%d' ]
	);

	$wpdb->delete(
		'crm_merchant_invites',
		[ 'merchant_id' => $merchant_id ],
		[ '%d' ]
	);
	$wpdb->delete(
		'crm_merchant_referrals',
		[ 'referral_merchant_id' => $merchant_id ],
		[ '%d' ]
	);

	$deleted = $wpdb->delete(
		'crm_merchants',
		[ 'id' => $merchant_id ],
		[ '%d' ]
	);
	if ( ! $deleted ) {
		return new WP_Error( 'merchant_delete_failed', 'Не удалось удалить мерчанта.' );
	}

	$avatar_path = trim( (string) ( $row->telegram_avatar_path ?? '' ) );
	if ( $avatar_path !== '' ) {
		$avatar_path = wp_normalize_path( $avatar_path );
		$theme_dir   = wp_normalize_path( get_template_directory() );
		if ( strpos( $avatar_path, $theme_dir ) === 0 && is_file( $avatar_path ) ) {
			@unlink( $avatar_path );
		}
	}

	$actor_user_id = $actor_user_id > 0 ? $actor_user_id : get_current_user_id();
	crm_log_entity(
		'merchant.hard_deleted',
		'users',
		'delete',
		'Мерчант физически удалён',
		'merchant',
		$merchant_id,
		[
			'org_id'  => (int) $row->company_id,
			'context' => [
				'deleted_by' => $actor_user_id,
				'chat_id'    => (string) ( $row->chat_id ?? '' ),
				'name'       => (string) ( $row->name ?? '' ),
			],
		]
	);

	return [
		'id'         => (int) $row->id,
		'company_id' => (int) $row->company_id,
		'name'       => (string) ( $row->name ?? '' ),
		'chat_id'    => (string) ( $row->chat_id ?? '' ),
	];
}

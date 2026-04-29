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
		                WHEN entry_type IN ('bonus_accrual','bonus_adjustment','bonus_withdrawal','manual_credit','manual_debit')
		                  THEN amount
		                ELSE 0
		            END) AS bonus_balance,
		        SUM(CASE
		                WHEN entry_type IN ('referral_accrual','referral_adjustment')
		                  THEN amount
		                ELSE 0
		            END) AS referral_balance,
		        SUM(amount) AS total_balance
		 FROM crm_merchant_wallet_ledger
		 WHERE merchant_id IN ($sql_ids)
		 GROUP BY merchant_id",
		ARRAY_A
	) ?: [];

	$map = [];
	foreach ( $rows as $row ) {
		$merchant_id = (int) $row['merchant_id'];
		$map[ $merchant_id ] = [
			'bonus_balance'    => (float) ( $row['bonus_balance'] ?? 0 ),
			'referral_balance' => (float) ( $row['referral_balance'] ?? 0 ),
			'total_balance'    => (float) ( $row['total_balance'] ?? 0 ),
		];
	}

	return $map;
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

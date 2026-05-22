<?php
/**
 * Malibu Exchange — Users Module
 *
 * Работает поверх стандартных таблиц WordPress (wp_users, wp_usermeta).
 * Бизнес-роли и статусы — в CRM RBAC (inc/rbac.php).
 *
 * Этот файл отвечает только за:
 *   - трекинг последнего входа (usermeta + crm_user_last_login)
 *   - вспомогательные функции для отображения (аватар, last_login)
 *   - проверку прав на управление пользователями через CRM
 *
 * ─────────────────────────────────────────────────────────
 * SQL таблица истории входов (создаётся один раз):
 * ─────────────────────────────────────────────────────────
 *
 * CREATE TABLE IF NOT EXISTS `crm_user_last_login` (
 *   `id`         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
 *   `user_id`    BIGINT(20) UNSIGNED NOT NULL,
 *   `login_at`   DATETIME            NOT NULL,
 *   `ip_address` VARCHAR(45)         DEFAULT NULL,
 *   `user_agent` VARCHAR(512)        DEFAULT NULL,
 *   PRIMARY KEY (`id`),
 *   KEY `idx_user_id`  (`user_id`),
 *   KEY `idx_login_at` (`login_at`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── WP-хуки ────────────────────────────────────────────────────────────────

/**
 * Фиксируем время последнего визита авторизованного пользователя.
 * Срабатывает на каждый запрос, пишет в БД не чаще раза в 5 минут.
 */
add_action( 'init', 'me_users_track_last_login' );
function me_users_track_last_login(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}

	global $wpdb;

	$user_id = get_current_user_id();
	$now     = current_time( 'mysql' );
	$last    = (string) get_user_meta( $user_id, 'last_login_at', true );

	// Пишем не чаще раза в 5 минут
	if ( $last && ( strtotime( $now ) - strtotime( $last ) ) < 300 ) {
		return;
	}

	update_user_meta( $user_id, 'last_login_at', $now );

	$wpdb->insert(
		'crm_user_last_login',
		[
			'user_id'    => $user_id,
			'login_at'   => $now,
			'ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null,
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : null,
		],
		[ '%d', '%s', '%s', '%s' ]
	);
}

// ─── Проверка прав ───────────────────────────────────────────────────────────

/**
 * Может ли текущий пользователь управлять пользователями.
 * Делегирует в CRM RBAC (permission: users.view).
 * uid=1 всегда имеет доступ.
 */
function me_users_can_manage(): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	return crm_can_manage_users();
}

/**
 * Только user_id === 1 может физически удалять пользователей.
 */
function me_users_can_hard_delete(): bool {
	return is_user_logged_in() && get_current_user_id() === 1;
}

// ─── Вспомогательные функции ─────────────────────────────────────────────────

/**
 * Получить время последнего входа (MySQL datetime или '').
 * Сначала читает из crm_user_last_login, фолбэк — usermeta.
 */
function me_users_get_last_login( int $user_id ): string {
	global $wpdb;

	$row = $wpdb->get_var( $wpdb->prepare(
		'SELECT login_at FROM crm_user_last_login WHERE user_id = %d ORDER BY login_at DESC LIMIT 1',
		$user_id
	) );

	if ( $row ) {
		return (string) $row;
	}

	return (string) get_user_meta( $user_id, 'last_login_at', true );
}

/**
 * Инициальная буква для аватара пользователя.
 */
function me_users_avatar_letter( WP_User $user ): string {
	$name = $user->display_name ?: $user->user_login;
	return strtoupper( mb_substr( $name, 0, 1 ) );
}

/**
 * URL до локального avatar asset внутри темы.
 */
function me_users_avatar_asset_url( string $filename ): string {
	$filename = trim( wp_basename( $filename ) );
	if ( $filename === '' ) {
		return '';
	}

	$path = trailingslashit( get_template_directory() ) . 'assets/img/avatars/' . $filename;
	if ( ! is_file( $path ) ) {
		return '';
	}

	return add_query_arg(
		'v',
		(string) filemtime( $path ),
		trailingslashit( get_template_directory_uri() ) . 'assets/img/avatars/' . $filename
	);
}

/**
 * Специальный системный аватар для root.
 */
function me_users_root_avatar_url(): string {
	return me_users_avatar_asset_url( 'root-avatar.png' );
}

/**
 * Нейтральный fallback для пользователей без аватара.
 */
function me_users_placeholder_avatar_url(): string {
	return me_users_avatar_asset_url( 'user-placeholder.png' );
}

/**
 * Проверяет theme-local URL и отбрасывает битые ссылки на удалённые локальные файлы.
 */
function me_users_normalize_avatar_url( string $url ): string {
	$url = trim( $url );
	if ( $url === '' ) {
		return '';
	}

	$query = wp_parse_url( $url, PHP_URL_QUERY );
	if ( is_string( $query ) && $query !== '' ) {
		$params = [];
		parse_str( $query, $params );

		$default = isset( $params['d'] ) ? strtolower( trim( (string) $params['d'] ) ) : '';
		if ( $default === '' && isset( $params['default'] ) ) {
			$default = strtolower( trim( (string) $params['default'] ) );
		}

		if ( $default === '404' ) {
			return '';
		}
	}

	$theme_uri_path = wp_parse_url( trailingslashit( get_template_directory_uri() ), PHP_URL_PATH );
	$url_path       = wp_parse_url( $url, PHP_URL_PATH );

	if ( is_string( $theme_uri_path ) && is_string( $url_path ) && strpos( $url_path, $theme_uri_path ) === 0 ) {
		$relative = ltrim( substr( $url_path, strlen( $theme_uri_path ) ), '/' );
		$path     = trailingslashit( get_template_directory() ) . $relative;
		if ( ! is_file( $path ) ) {
			return '';
		}
	}

	return esc_url_raw( $url );
}

/**
 * Telegram-avatar пользователя в контуре его primary-компании.
 *
 * @return array<string,mixed>
 */
function me_users_get_telegram_avatar_context( int $user_id ): array {
	static $cache = [];

	if ( isset( $cache[ $user_id ] ) ) {
		return $cache[ $user_id ];
	}

	$empty = [
		'company_id'          => 0,
		'telegram_account_id' => 0,
		'telegram_user_id'    => '',
		'chat_id'             => '',
		'telegram_username'   => '',
		'telegram_status'     => '',
		'telegram_avatar_url' => '',
		'can_refresh'         => false,
	];

	if ( $user_id <= 0 || crm_is_root( $user_id ) ) {
		$cache[ $user_id ] = $empty;
		return $cache[ $user_id ];
	}

	$company_id = crm_get_current_user_company_id( $user_id );
	if ( $company_id <= 0 ) {
		$cache[ $user_id ] = $empty;
		return $cache[ $user_id ];
	}

	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, company_id, user_id, chat_id, telegram_user_id, telegram_username, telegram_avatar_url, status
			 FROM crm_user_telegram_accounts
			 WHERE company_id = %d
			   AND user_id = %d
			 LIMIT 1",
			$company_id,
			$user_id
		)
	);

	if ( ! $row ) {
		$cache[ $user_id ] = $empty;
		return $cache[ $user_id ];
	}

	$telegram_user_id = trim( (string) ( $row->telegram_user_id ?? '' ) );
	$chat_id          = trim( (string) ( $row->chat_id ?? '' ) );

	$cache[ $user_id ] = [
		'company_id'          => (int) ( $row->company_id ?? 0 ),
		'telegram_account_id' => (int) ( $row->id ?? 0 ),
		'telegram_user_id'    => $telegram_user_id,
		'chat_id'             => $chat_id,
		'telegram_username'   => trim( (string) ( $row->telegram_username ?? '' ) ),
		'telegram_status'     => trim( (string) ( $row->status ?? '' ) ),
		'telegram_avatar_url' => me_users_normalize_avatar_url( (string) ( $row->telegram_avatar_url ?? '' ) ),
		'can_refresh'         => $telegram_user_id !== '' || $chat_id !== '',
	];

	return $cache[ $user_id ];
}

/**
 * URL Telegram-аватара пользователя, если он уже привязан.
 */
function me_users_get_telegram_avatar_url( int $user_id ): string {
	$ctx = me_users_get_telegram_avatar_context( $user_id );

	return (string) ( $ctx['telegram_avatar_url'] ?? '' );
}

/**
 * Можно ли запросить повторное обновление Telegram-аватара для пользователя.
 */
function me_users_can_refresh_telegram_avatar( int $user_id ): bool {
	$ctx = me_users_get_telegram_avatar_context( $user_id );

	return ! empty( $ctx['can_refresh'] );
}

/**
 * Пытается получить настоящий WordPress-аватар пользователя.
 * Используем default=404, чтобы отличить реальный avatar от штатного fallback.
 */
function me_users_wordpress_avatar_url( int $user_id, int $size = 96 ): string {
	if ( $user_id <= 0 ) {
		return '';
	}

	$avatar = get_avatar_data(
		$user_id,
		[
			'size'          => max( 24, $size ),
			'default'       => '404',
			'force_default' => false,
		]
	);

	$url = isset( $avatar['url'] ) ? trim( (string) $avatar['url'] ) : '';
	if ( empty( $avatar['found_avatar'] ) || $url === '' ) {
		$html = get_avatar(
			$user_id,
			max( 24, $size ),
			'404',
			'',
			[
				'force_default' => false,
			]
		);

		if ( preg_match( '/\ssrc=(["\'])(.*?)\1/i', (string) $html, $matches ) ) {
			$src = trim( html_entity_decode( (string) ( $matches[2] ?? '' ), ENT_QUOTES, 'UTF-8' ) );
			if (
				$src !== ''
				&& stripos( $src, 'd=404' ) === false
				&& stripos( $src, 'default=404' ) === false
			) {
				return me_users_normalize_avatar_url( $src );
			}
		}

		return '';
	}

	return me_users_normalize_avatar_url( $url );
}

/**
 * Единый resolver аватара пользователя.
 */
function me_users_avatar_url( int $user_id, int $size = 96 ): string {
	if ( $user_id <= 0 ) {
		return me_users_placeholder_avatar_url();
	}

	if ( crm_is_root( $user_id ) ) {
		$root_avatar = me_users_root_avatar_url();
		return $root_avatar !== '' ? $root_avatar : me_users_placeholder_avatar_url();
	}

	$wp_avatar = me_users_wordpress_avatar_url( $user_id, $size );
	if ( $wp_avatar !== '' ) {
		return $wp_avatar;
	}

	$telegram_avatar = me_users_get_telegram_avatar_url( $user_id );
	if ( $telegram_avatar !== '' ) {
		return $telegram_avatar;
	}

	return me_users_placeholder_avatar_url();
}

/**
 * Готовая HTML-обёртка аватара для списков и header.
 */
function me_users_render_avatar( WP_User $user, array $args = [] ): string {
	$size          = max( 24, (int) ( $args['size'] ?? 32 ) );
	$wrapper_class = trim( (string) ( $args['wrapper_class'] ?? '' ) );
	$img_class     = trim( (string) ( $args['img_class'] ?? '' ) );
	$label         = trim( (string) ( $args['label'] ?? ( $user->display_name ?: $user->user_login ?: 'User' ) ) );
	$alt           = trim( (string) ( $args['alt'] ?? $label ) );
	$avatar_url    = me_users_avatar_url( (int) $user->ID, $size );
	$placeholder   = me_users_placeholder_avatar_url();
	$wrapper_style = sprintf(
		'width:%1$dpx;height:%1$dpx;min-width:%1$dpx;border-radius:50%%;overflow:hidden;display:inline-flex;align-items:center;justify-content:center;flex:0 0 %1$dpx;background:#eef1f4;border:1px solid rgba(36,48,64,.08);box-shadow:inset 0 1px 0 rgba(255,255,255,.55);',
		$size
	);

	$classes = trim( implode( ' ', array_filter( [ $wrapper_class ] ) ) );

	if ( $avatar_url !== '' ) {
		$img_attrs = '';
		if ( $placeholder !== '' && $placeholder !== $avatar_url ) {
			$img_attrs = sprintf(
				' onerror="this.onerror=null;this.src=\'%1$s\';this.setAttribute(\'data-src\',\'%1$s\');this.setAttribute(\'data-src-retina\',\'%1$s\');"',
				esc_js( $placeholder )
			);
		}

		return sprintf(
			'<span class="%1$s" style="%2$s"><img class="%3$s" src="%4$s" data-src="%4$s" data-src-retina="%4$s" alt="%5$s" width="%6$d" height="%6$d" style="display:block;width:100%%;height:100%%;object-fit:cover;"%7$s></span>',
			esc_attr( $classes ),
			esc_attr( $wrapper_style ),
			esc_attr( $img_class ),
			esc_url( $avatar_url ),
			esc_attr( $alt ),
			$size,
			$img_attrs
		);
	}

	return sprintf(
		'<span class="%1$s" style="%2$s;color:#7d8b96;font-weight:700;font-size:%3$dpx;letter-spacing:.02em;">%4$s</span>',
		esc_attr( $classes ),
		esc_attr( $wrapper_style ),
		max( 12, (int) floor( $size * 0.38 ) ),
		esc_html( me_users_avatar_letter( $user ) )
	);
}

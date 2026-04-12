<?php
/**
 * Malibu Exchange — Users AJAX Handlers
 *
 * Все обработчики доступны только залогиненным пользователям
 * с правами me_users_can_manage().
 *
 * Действия:
 *   me_save_user          — создать / обновить пользователя
 *   me_set_user_status    — изменить статус (active / blocked / archived)
 *   me_delete_user        — мягкое (archived) или физическое удаление
 *   me_toggle_admin_role  — назначить / снять роль administrator
 *   me_toggle_user_manager — выдать / отозвать права менеджера (только uid=1)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Хелпер: отправить JSON-ошибку ───────────────────────────────────────────
function _me_ajax_error( string $message, int $code = 400 ): void {
	wp_send_json_error( [ 'message' => $message ], $code );
}

// ─── Хелпер: проверить право управления ──────────────────────────────────────
function _me_ajax_check_manage(): void {
	if ( ! is_user_logged_in() || ! me_users_can_manage() ) {
		_me_ajax_error( 'Недостаточно прав.', 403 );
	}
}

// ════════════════════════════════════════════════════════════════════════════════
// 1. СОХРАНИТЬ ПОЛЬЗОВАТЕЛЯ (создать или обновить)
// ════════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_save_user', 'me_ajax_save_user' );
function me_ajax_save_user(): void {
	_me_ajax_check_manage();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_users_save' ) ) {
		_me_ajax_error( 'Нарушена безопасность запроса. Обновите страницу.' );
	}

	$user_id      = (int) ( $_POST['user_id'] ?? 0 );
	$is_new       = $user_id === 0;
	$current_uid  = get_current_user_id();

	// ── Базовые поля ──────────────────────────────────────────────────────────
	$user_login   = sanitize_user( wp_unslash( $_POST['user_login']   ?? '' ) );
	$user_email   = sanitize_email( wp_unslash( $_POST['user_email']  ?? '' ) );
	$user_pass    = wp_unslash( $_POST['user_pass'] ?? '' ); // пароль не sanitize
	$first_name   = sanitize_text_field( wp_unslash( $_POST['first_name']   ?? '' ) );
	$last_name    = sanitize_text_field( wp_unslash( $_POST['last_name']    ?? '' ) );
	$display_name = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
	$role         = sanitize_key( $_POST['role'] ?? 'subscriber' );
	$status       = sanitize_key( $_POST['account_status'] ?? ME_USER_STATUS_ACTIVE );
	$notes        = sanitize_textarea_field( wp_unslash( $_POST['me_notes'] ?? '' ) );

	// ── Валидация ────────────────────────────────────────────────────────────
	if ( $user_email === '' ) {
		_me_ajax_error( 'Email обязателен.' );
	}

	if ( ! is_email( $user_email ) ) {
		_me_ajax_error( 'Неверный формат email.' );
	}

	// Проверить роль
	global $wp_roles;
	if ( ! array_key_exists( $role, $wp_roles->roles ) ) {
		$role = 'subscriber';
	}

	// Статус
	if ( ! in_array( $status, [ ME_USER_STATUS_ACTIVE, ME_USER_STATUS_BLOCKED ], true ) ) {
		$status = ME_USER_STATUS_ACTIVE;
	}

	// ── СОЗДАНИЕ нового пользователя ─────────────────────────────────────────
	if ( $is_new ) {
		if ( $user_login === '' ) {
			_me_ajax_error( 'Username обязателен.' );
		}
		if ( ! validate_username( $user_login ) ) {
			_me_ajax_error( 'Username содержит недопустимые символы.' );
		}
		if ( username_exists( $user_login ) ) {
			_me_ajax_error( "Username «{$user_login}» уже занят." );
		}
		if ( email_exists( $user_email ) ) {
			_me_ajax_error( "Email «{$user_email}» уже зарегистрирован." );
		}
		if ( $user_pass === '' ) {
			_me_ajax_error( 'Пароль обязателен при создании пользователя.' );
		}
		if ( strlen( $user_pass ) < 6 ) {
			_me_ajax_error( 'Пароль должен содержать не менее 6 символов.' );
		}

		$userdata = [
			'user_login'   => $user_login,
			'user_email'   => $user_email,
			'user_pass'    => $user_pass,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => $display_name !== '' ? $display_name : $user_login,
			'role'         => $role,
		];

		$result = wp_insert_user( $userdata );

		if ( is_wp_error( $result ) ) {
			_me_ajax_error( $result->get_error_message() );
		}

		$user_id = (int) $result;

		// Meta
		update_user_meta( $user_id, 'account_status', $status );
		if ( $notes !== '' ) {
			update_user_meta( $user_id, 'me_notes', $notes );
		}

		wp_send_json_success( [
			'message' => "Пользователь «{$user_login}» успешно создан.",
			'user_id' => $user_id,
		] );
	}

	// ── ОБНОВЛЕНИЕ существующего пользователя ─────────────────────────────────
	// Нельзя редактировать себя через этот интерфейс (только через WP-профиль)
	if ( $user_id === $current_uid && $current_uid !== 1 ) {
		_me_ajax_error( 'Редактируйте собственный профиль через настройки аккаунта.' );
	}

	$target_user = get_userdata( $user_id );
	if ( ! $target_user ) {
		_me_ajax_error( 'Пользователь не найден.' );
	}

	// Нельзя менять email на уже существующий (другого пользователя)
	$existing_email = email_exists( $user_email );
	if ( $existing_email && (int) $existing_email !== $user_id ) {
		_me_ajax_error( "Email «{$user_email}» уже используется другим пользователем." );
	}

	$userdata = [
		'ID'           => $user_id,
		'user_email'   => $user_email,
		'first_name'   => $first_name,
		'last_name'    => $last_name,
		'display_name' => $display_name !== '' ? $display_name : $target_user->display_name,
		'role'         => $role,
	];

	// Обновить пароль только если передан
	if ( $user_pass !== '' ) {
		if ( strlen( $user_pass ) < 6 ) {
			_me_ajax_error( 'Пароль должен содержать не менее 6 символов.' );
		}
		$userdata['user_pass'] = $user_pass;
	}

	$result = wp_update_user( $userdata );

	if ( is_wp_error( $result ) ) {
		_me_ajax_error( $result->get_error_message() );
	}

	// Meta
	update_user_meta( $user_id, 'account_status', $status );
	update_user_meta( $user_id, 'me_notes', $notes );

	wp_send_json_success( [
		'message' => "Пользователь «{$target_user->user_login}» обновлён.",
		'user_id' => $user_id,
	] );
}

// ════════════════════════════════════════════════════════════════════════════════
// 2. ИЗМЕНИТЬ СТАТУС ПОЛЬЗОВАТЕЛЯ
// ════════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_set_user_status', 'me_ajax_set_user_status' );
function me_ajax_set_user_status(): void {
	_me_ajax_check_manage();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_users_status' ) ) {
		_me_ajax_error( 'Нарушена безопасность запроса.' );
	}

	$user_id = (int) ( $_POST['user_id'] ?? 0 );
	$status  = sanitize_key( $_POST['status'] ?? '' );

	if ( $user_id <= 0 ) {
		_me_ajax_error( 'Неверный ID пользователя.' );
	}

	$result = me_users_set_status( $user_id, $status );

	if ( $result !== true ) {
		_me_ajax_error( $result );
	}

	// Разрушить активные сессии при блокировке / архивировании
	if ( in_array( $status, [ ME_USER_STATUS_BLOCKED, ME_USER_STATUS_ARCHIVED ], true ) ) {
		$sessions = WP_Session_Tokens::get_instance( $user_id );
		$sessions->destroy_all();
	}

	$labels = [
		ME_USER_STATUS_ACTIVE   => 'Active',
		ME_USER_STATUS_BLOCKED  => 'Blocked',
		ME_USER_STATUS_ARCHIVED => 'Archived',
	];
	$badge_classes = [
		ME_USER_STATUS_ACTIVE   => 'success',
		ME_USER_STATUS_BLOCKED  => 'danger',
		ME_USER_STATUS_ARCHIVED => 'secondary',
	];

	wp_send_json_success( [
		'message'     => "Статус изменён на «{$labels[ $status ]}».",
		'status'      => $status,
		'label'       => $labels[ $status ],
		'badge_class' => $badge_classes[ $status ],
	] );
}

// ════════════════════════════════════════════════════════════════════════════════
// 3. УДАЛИТЬ ПОЛЬЗОВАТЕЛЯ (мягко = archived, жёстко = физическое удаление)
// ════════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_delete_user', 'me_ajax_delete_user' );
function me_ajax_delete_user(): void {
	_me_ajax_check_manage();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_users_delete' ) ) {
		_me_ajax_error( 'Нарушена безопасность запроса.' );
	}

	$user_id   = (int) ( $_POST['user_id']   ?? 0 );
	$hard      = (bool) ( $_POST['hard']      ?? false );
	$reassign  = (int) ( $_POST['reassign']  ?? 0 ); // кому перенести контент при жёстком удалении

	if ( $user_id <= 0 ) {
		_me_ajax_error( 'Неверный ID пользователя.' );
	}

	$current_uid = get_current_user_id();

	if ( $user_id === $current_uid ) {
		_me_ajax_error( 'Нельзя удалить собственный аккаунт.' );
	}
	if ( $user_id === 1 ) {
		_me_ajax_error( 'Нельзя удалить главного администратора.' );
	}

	$target_user = get_userdata( $user_id );
	if ( ! $target_user ) {
		_me_ajax_error( 'Пользователь не найден.' );
	}

	// Физическое удаление — только для uid=1
	if ( $hard ) {
		if ( ! me_users_can_hard_delete() ) {
			_me_ajax_error( 'Недостаточно прав для физического удаления.', 403 );
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$reassign_to = $reassign > 0 ? $reassign : null;
		$deleted = wp_delete_user( $user_id, $reassign_to );

		if ( ! $deleted ) {
			_me_ajax_error( 'Ошибка при удалении пользователя.' );
		}

		wp_send_json_success( [
			'message' => "Пользователь «{$target_user->user_login}» физически удалён.",
			'hard'    => true,
		] );
	}

	// Мягкое удаление = архивирование
	$result = me_users_set_status( $user_id, ME_USER_STATUS_ARCHIVED );
	if ( $result !== true ) {
		_me_ajax_error( $result );
	}

	// Уничтожить сессии
	$sessions = WP_Session_Tokens::get_instance( $user_id );
	$sessions->destroy_all();

	wp_send_json_success( [
		'message' => "Пользователь «{$target_user->user_login}» перемещён в архив.",
		'hard'    => false,
	] );
}

// ════════════════════════════════════════════════════════════════════════════════
// 4. НАЗНАЧИТЬ / СНЯТЬ РОЛЬ ADMINISTRATOR
// ════════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_toggle_admin_role', 'me_ajax_toggle_admin_role' );
function me_ajax_toggle_admin_role(): void {
	_me_ajax_check_manage();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_users_admin' ) ) {
		_me_ajax_error( 'Нарушена безопасность запроса.' );
	}

	$user_id = (int) ( $_POST['user_id'] ?? 0 );
	$action  = sanitize_key( $_POST['action_type'] ?? '' ); // 'make' | 'remove'

	if ( $user_id <= 0 ) {
		_me_ajax_error( 'Неверный ID пользователя.' );
	}
	if ( $user_id === get_current_user_id() ) {
		_me_ajax_error( 'Нельзя изменить роль собственного аккаунта.' );
	}
	if ( $user_id === 1 && $action === 'remove' ) {
		_me_ajax_error( 'Нельзя снять роль администратора с uid=1.' );
	}

	$target_user = new WP_User( $user_id );
	if ( ! $target_user->exists() ) {
		_me_ajax_error( 'Пользователь не найден.' );
	}

	if ( $action === 'make' ) {
		$target_user->set_role( 'administrator' );
		$new_role = 'administrator';
		$message  = "Пользователь «{$target_user->user_login}» назначен администратором.";
	} else {
		// Снять administrator, назначить subscriber
		$target_user->set_role( 'subscriber' );
		$new_role = 'subscriber';
		$message  = "Роль администратора снята с пользователя «{$target_user->user_login}».";
	}

	wp_send_json_success( [
		'message'  => $message,
		'new_role' => $new_role,
	] );
}

// ════════════════════════════════════════════════════════════════════════════════
// 5. ВЫДАТЬ / ОТОЗВАТЬ ПРАВА МЕНЕДЖЕРА (только uid=1)
// ════════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_toggle_user_manager', 'me_ajax_toggle_user_manager' );
function me_ajax_toggle_user_manager(): void {
	if ( ! is_user_logged_in() || ! me_users_can_hard_delete() ) {
		_me_ajax_error( 'Недостаточно прав.', 403 );
	}

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_users_admin' ) ) {
		_me_ajax_error( 'Нарушена безопасность запроса.' );
	}

	$user_id = (int) ( $_POST['user_id'] ?? 0 );
	$action  = sanitize_key( $_POST['action_type'] ?? '' ); // 'grant' | 'revoke'

	if ( $user_id <= 0 || $user_id === 1 ) {
		_me_ajax_error( 'Неверный ID пользователя.' );
	}

	$target_user = get_userdata( $user_id );
	if ( ! $target_user ) {
		_me_ajax_error( 'Пользователь не найден.' );
	}

	if ( $action === 'grant' ) {
		update_user_meta( $user_id, 'me_user_manager', '1' );
		$message = "Права менеджера выданы пользователю «{$target_user->user_login}».";
	} else {
		delete_user_meta( $user_id, 'me_user_manager' );
		$message = "Права менеджера отозваны у пользователя «{$target_user->user_login}».";
	}

	wp_send_json_success( [
		'message' => $message,
		'action'  => $action,
	] );
}

// ════════════════════════════════════════════════════════════════════════════════
// 6. ПОЛУЧИТЬ ДАННЫЕ ПОЛЬЗОВАТЕЛЯ ДЛЯ ФОРМЫ РЕДАКТИРОВАНИЯ
// ════════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_get_user_data', 'me_ajax_get_user_data' );
function me_ajax_get_user_data(): void {
	_me_ajax_check_manage();

	if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'me_users_save' ) ) {
		_me_ajax_error( 'Нарушена безопасность запроса.' );
	}

	$user_id = (int) ( $_GET['user_id'] ?? 0 );
	if ( $user_id <= 0 ) {
		_me_ajax_error( 'Неверный ID.' );
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		_me_ajax_error( 'Пользователь не найден.' );
	}

	wp_send_json_success( [
		'id'             => $user->ID,
		'user_login'     => $user->user_login,
		'user_email'     => $user->user_email,
		'first_name'     => $user->first_name,
		'last_name'      => $user->last_name,
		'display_name'   => $user->display_name,
		'role'           => me_users_get_primary_role( $user ),
		'account_status' => me_users_get_status( $user->ID ),
		'me_notes'       => (string) get_user_meta( $user->ID, 'me_notes', true ),
	] );
}

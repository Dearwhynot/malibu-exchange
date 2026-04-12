<?php
/**
 * Malibu Exchange — Users AJAX Handlers
 *
 * Все обработчики доступны только залогиненным пользователям
 * с правом users.view (crm_can_manage_users).
 *
 * Действия:
 *   me_save_user       — создать / обновить пользователя + CRM-аккаунт + роли
 *   me_set_user_status — изменить CRM-статус
 *   me_delete_user     — мягкое (archived) или физическое удаление
 *   me_get_user_data   — данные пользователя для формы редактирования
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Хелперы ─────────────────────────────────────────────────────────────────

function _me_ajax_error( string $message ): void {
	wp_send_json_error( [ 'message' => $message ] );
}

function _me_ajax_check_manage(): void {
	if ( ! is_user_logged_in() || ! crm_can_manage_users() ) {
		_me_ajax_error( 'Недостаточно прав.' );
	}
}

// ════════════════════════════════════════════════════════════════════════════
// 1. СОХРАНИТЬ ПОЛЬЗОВАТЕЛЯ (создать или обновить)
// ════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_save_user', 'me_ajax_save_user' );
function me_ajax_save_user(): void {
	_me_ajax_check_manage();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_users_save' ) ) {
		_me_ajax_error( 'Нарушена безопасность запроса. Обновите страницу.' );
	}

	$current_uid  = get_current_user_id();
	$user_id      = (int) ( $_POST['user_id'] ?? 0 );
	$is_new       = $user_id === 0;

	// ── Базовые WP-поля ───────────────────────────────────────────────────────
	$user_login   = sanitize_user( wp_unslash( $_POST['user_login']   ?? '' ) );
	$user_email   = sanitize_email( wp_unslash( $_POST['user_email']  ?? '' ) );
	$user_pass    = wp_unslash( $_POST['user_pass'] ?? '' );
	$first_name   = sanitize_text_field( wp_unslash( $_POST['first_name']   ?? '' ) );
	$last_name    = sanitize_text_field( wp_unslash( $_POST['last_name']    ?? '' ) );
	$display_name = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );

	// ── CRM-поля ─────────────────────────────────────────────────────────────
	$crm_status        = sanitize_key( $_POST['crm_status']        ?? CRM_STATUS_ACTIVE );
	$phone             = sanitize_text_field( wp_unslash( $_POST['phone']             ?? '' ) );
	$telegram_username = sanitize_text_field( wp_unslash( $_POST['telegram_username'] ?? '' ) );
	$telegram_id       = (int) ( $_POST['telegram_id'] ?? 0 );
	$department        = sanitize_text_field( wp_unslash( $_POST['department']        ?? '' ) );
	$position_title    = sanitize_text_field( wp_unslash( $_POST['position_title']    ?? '' ) );
	$note              = sanitize_textarea_field( wp_unslash( $_POST['note']          ?? '' ) );

	// Роли (массив id)
	$crm_role_ids = array_map( 'intval', (array) ( $_POST['crm_role_ids'] ?? [] ) );

	// ── Валидация ─────────────────────────────────────────────────────────────
	if ( $user_email === '' || ! is_email( $user_email ) ) {
		_me_ajax_error( 'Укажите корректный email.' );
	}

	$valid_statuses = [ CRM_STATUS_ACTIVE, CRM_STATUS_BLOCKED, CRM_STATUS_ARCHIVED, CRM_STATUS_PENDING ];
	if ( ! in_array( $crm_status, $valid_statuses, true ) ) {
		$crm_status = CRM_STATUS_ACTIVE;
	}

	// ── СОЗДАНИЕ нового пользователя ──────────────────────────────────────────
	if ( $is_new ) {
		if ( $user_login === '' || ! validate_username( $user_login ) ) {
			_me_ajax_error( 'Укажите корректный username.' );
		}
		if ( username_exists( $user_login ) ) {
			_me_ajax_error( "Username «{$user_login}» уже занят." );
		}
		if ( email_exists( $user_email ) ) {
			_me_ajax_error( "Email «{$user_email}» уже зарегистрирован." );
		}
		if ( $user_pass === '' || strlen( $user_pass ) < 6 ) {
			_me_ajax_error( 'Пароль обязателен и должен содержать не менее 6 символов.' );
		}

		$result = wp_insert_user( [
			'user_login'   => $user_login,
			'user_email'   => $user_email,
			'user_pass'    => $user_pass,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => $display_name !== '' ? $display_name : $user_login,
			'role'         => 'subscriber', // WP-роль всегда subscriber
		] );

		if ( is_wp_error( $result ) ) {
			_me_ajax_error( $result->get_error_message() );
		}

		$user_id = (int) $result;

		// CRM-аккаунт
		crm_ensure_user_account( $user_id );
		crm_update_user_account( $user_id, [
			'status'           => $crm_status,
			'phone'            => $phone,
			'telegram_username' => $telegram_username,
			'telegram_id'      => $telegram_id ?: null,
			'department'       => $department,
			'position_title'   => $position_title,
			'note'             => $note,
		] );

		// CRM-роли (назначать может только пользователь с users.assign_roles)
		if ( crm_user_has_permission( $current_uid, 'users.assign_roles' ) ) {
			crm_assign_roles( $user_id, $crm_role_ids, $current_uid );
		}

		wp_send_json_success( [
			'message' => "Пользователь «{$user_login}» создан.",
			'user_id' => $user_id,
		] );
	}

	// ── ОБНОВЛЕНИЕ существующего пользователя ─────────────────────────────────
	if ( $user_id === $current_uid && $current_uid !== 1 ) {
		_me_ajax_error( 'Редактируйте собственный профиль через настройки аккаунта.' );
	}

	$target_user = get_userdata( $user_id );
	if ( ! $target_user ) {
		_me_ajax_error( 'Пользователь не найден.' );
	}

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
	];

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

	// CRM-аккаунт
	crm_update_user_account( $user_id, [
		'status'           => $crm_status,
		'phone'            => $phone,
		'telegram_username' => $telegram_username,
		'telegram_id'      => $telegram_id ?: null,
		'department'       => $department,
		'position_title'   => $position_title,
		'note'             => $note,
	] );

	// CRM-роли
	if ( crm_user_has_permission( $current_uid, 'users.assign_roles' ) ) {
		crm_assign_roles( $user_id, $crm_role_ids, $current_uid );
	}

	wp_send_json_success( [
		'message' => "Пользователь «{$target_user->user_login}» обновлён.",
		'user_id' => $user_id,
	] );
}

// ════════════════════════════════════════════════════════════════════════════
// 2. ИЗМЕНИТЬ CRM-СТАТУС ПОЛЬЗОВАТЕЛЯ
// ════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_set_user_status', 'me_ajax_set_user_status' );
function me_ajax_set_user_status(): void {
	_me_ajax_check_manage();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_users_status' ) ) {
		_me_ajax_error( 'Нарушена безопасность запроса.' );
	}

	$user_id     = (int) ( $_POST['user_id'] ?? 0 );
	$status      = sanitize_key( $_POST['status'] ?? '' );
	$current_uid = get_current_user_id();

	if ( $user_id <= 0 ) {
		_me_ajax_error( 'Неверный ID пользователя.' );
	}
	if ( $user_id === $current_uid ) {
		_me_ajax_error( 'Нельзя изменить статус собственного аккаунта.' );
	}
	if ( $user_id === 1 && $current_uid !== 1 ) {
		_me_ajax_error( 'Недостаточно прав для изменения этого аккаунта.' );
	}

	if ( ! crm_set_user_status( $user_id, $status ) ) {
		_me_ajax_error( 'Недопустимый статус.' );
	}

	// Разрушить сессии при блокировке / архивировании
	if ( in_array( $status, [ CRM_STATUS_BLOCKED, CRM_STATUS_ARCHIVED ], true ) ) {
		$sessions = WP_Session_Tokens::get_instance( $user_id );
		$sessions->destroy_all();
	}

	wp_send_json_success( [
		'message'     => 'Статус изменён на «' . crm_status_label( $status ) . '».',
		'status'      => $status,
		'label'       => crm_status_label( $status ),
		'badge_class' => crm_status_badge_class( $status ),
	] );
}

// ════════════════════════════════════════════════════════════════════════════
// 3. УДАЛИТЬ ПОЛЬЗОВАТЕЛЯ
// ════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_delete_user', 'me_ajax_delete_user' );
function me_ajax_delete_user(): void {
	_me_ajax_check_manage();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_users_delete' ) ) {
		_me_ajax_error( 'Нарушена безопасность запроса.' );
	}

	$user_id     = (int) ( $_POST['user_id']  ?? 0 );
	$hard        = (bool) ( $_POST['hard']     ?? false );
	$current_uid = get_current_user_id();

	if ( $user_id <= 0 )          _me_ajax_error( 'Неверный ID.' );
	if ( $user_id === $current_uid ) _me_ajax_error( 'Нельзя удалить собственный аккаунт.' );
	if ( $user_id === 1 )          _me_ajax_error( 'Нельзя удалить главного администратора.' );

	$target_user = get_userdata( $user_id );
	if ( ! $target_user ) {
		_me_ajax_error( 'Пользователь не найден.' );
	}

	if ( $hard ) {
		if ( ! me_users_can_hard_delete() ) {
			_me_ajax_error( 'Недостаточно прав для физического удаления.' );
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		if ( ! wp_delete_user( $user_id ) ) {
			_me_ajax_error( 'Ошибка при удалении.' );
		}
		wp_send_json_success( [
			'message' => "Пользователь «{$target_user->user_login}» физически удалён.",
			'hard'    => true,
		] );
	}

	// Мягкое удаление = archived
	crm_set_user_status( $user_id, CRM_STATUS_ARCHIVED );
	$sessions = WP_Session_Tokens::get_instance( $user_id );
	$sessions->destroy_all();

	wp_send_json_success( [
		'message' => "Пользователь «{$target_user->user_login}» перемещён в архив.",
		'hard'    => false,
	] );
}

// ════════════════════════════════════════════════════════════════════════════
// 4. ПОЛУЧИТЬ ДАННЫЕ ПОЛЬЗОВАТЕЛЯ ДЛЯ ФОРМЫ РЕДАКТИРОВАНИЯ
// ════════════════════════════════════════════════════════════════════════════
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

	$account   = crm_get_user_account( $user_id );
	$crm_roles = crm_get_user_roles( $user_id );

	wp_send_json_success( [
		'id'               => $user->ID,
		'user_login'       => $user->user_login,
		'user_email'       => $user->user_email,
		'first_name'       => $user->first_name,
		'last_name'        => $user->last_name,
		'display_name'     => $user->display_name,
		'crm_status'       => $account ? $account->status : CRM_STATUS_ACTIVE,
		'phone'            => $account ? ( $account->phone ?? '' ) : '',
		'telegram_username' => $account ? ( $account->telegram_username ?? '' ) : '',
		'telegram_id'      => $account ? ( $account->telegram_id ?? '' ) : '',
		'department'       => $account ? ( $account->department ?? '' ) : '',
		'position_title'   => $account ? ( $account->position_title ?? '' ) : '',
		'note'             => $account ? ( $account->note ?? '' ) : '',
		'role_ids'         => array_map( function ( $r ) { return (int) $r->id; }, $crm_roles ),
	] );
}

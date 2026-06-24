<?php
/**
 * Malibu Exchange — Companies AJAX Handlers
 *
 * Root управляет компаниями, а офисы компании создаются root либо
 * пользователем с permission offices.create внутри своей компании.
 *
 * Actions:
 *   me_list_companies      — список компаний с числом пользователей
 *   me_create_company      — создать новую компанию
 *   me_set_company_status  — root-only блокировка / разблокировка компании
 *   me_assign_user_company — назначить пользователя в компанию
 *   me_create_company_office — создать офис компании
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guard: только uid=1 может управлять компаниями.
 */
function _me_companies_root_only(): void {
	if ( ! is_user_logged_in() || ! crm_is_root( get_current_user_id() ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}
}

/**
 * Guard: root или пользователь с permission offices.create.
 */
function _me_companies_office_creator_only(): void {
	if ( ! is_user_logged_in() || ! crm_can_create_company_offices( get_current_user_id() ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}
}

// ════════════════════════════════════════════════════════════════════════════
// 1. СПИСОК КОМПАНИЙ
// ════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_list_companies', 'me_ajax_list_companies' );
function me_ajax_list_companies(): void {
	_me_companies_root_only();

	$companies = crm_get_all_companies_full();
	wp_send_json_success( [ 'companies' => $companies ] );
}

// ════════════════════════════════════════════════════════════════════════════
// 2. СОЗДАТЬ КОМПАНИЮ
// ════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_create_company', 'me_ajax_create_company' );
function me_ajax_create_company(): void {
	_me_companies_root_only();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_create_company' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ] );
	}

	$name    = sanitize_text_field( wp_unslash( $_POST['name']    ?? '' ) );
	$phone   = sanitize_text_field( wp_unslash( $_POST['phone']   ?? '' ) );
	$address = sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) );

	if ( $name === '' ) {
		wp_send_json_error( [ 'message' => 'Название компании обязательно.' ] );
	}

	global $wpdb;

	// Генерация уникального code из названия.
	$base_code = substr( sanitize_key( str_replace( ' ', '_', strtolower( $name ) ) ), 0, 50 );
	if ( $base_code === '' ) {
		$base_code = 'company';
	}
	$code   = $base_code;
	$suffix = 1;
	while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM crm_companies WHERE code = %s", $code ) ) ) {
		$code = $base_code . '_' . $suffix;
		$suffix++;
	}

	$wpdb->insert(
		'crm_companies',
		[
			'code'               => $code,
			'name'               => $name,
			'phone'              => $phone !== '' ? $phone : null,
			'address'            => $address !== '' ? $address : null,
			'status'             => 'active',
			'created_by_user_id' => get_current_user_id(),
		],
		[ '%s', '%s', '%s', '%s', '%s', '%d' ]
	);

	$company_id = (int) $wpdb->insert_id;
	if ( ! $company_id ) {
		wp_send_json_error( [ 'message' => 'Ошибка при создании компании.' ] );
	}

	crm_set_setting(
		'fintech_allowed_providers',
		crm_fintech_serialize_allowed_providers( crm_fintech_initial_allowed_providers_for_new_company() ),
		$company_id
	);
	if ( function_exists( 'crm_fintech_seed_company_settings' ) ) {
		crm_fintech_seed_company_settings( $company_id );
	}
	crm_telegram_seed_company_settings( $company_id );
	if ( function_exists( 'crm_company_seed_rub_usdt_fixation_settings' ) ) {
		crm_company_seed_rub_usdt_fixation_settings( $company_id );
	}
	crm_merchants_seed_company_settings( $company_id );

	crm_log( 'company.created', [
		'category'    => 'users',
		'level'       => 'info',
		'action'      => 'create',
		'message'     => "Создана компания «{$name}»",
		'target_type' => 'company',
		'target_id'   => $company_id,
		'context'     => [ 'name' => $name, 'code' => $code ],
	] );

	wp_send_json_success( [
		'message'    => "Компания «{$name}» создана.",
		'company_id' => $company_id,
		'company'    => [
			'id'         => $company_id,
			'code'       => $code,
			'name'       => $name,
			'status'     => 'active',
			'status_label' => crm_company_status_label( 'active' ),
			'status_badge' => crm_company_status_badge_class( 'active' ),
			'user_count' => 0,
			'phone'      => $phone,
			'address'    => $address,
			'note'       => '',
			'enabled_exchange_pairs' => [],
			'allowed_providers'      => crm_company_get_enabled_fintech_providers( $company_id ),
			'rub_usdt_fixation_mode' => function_exists( 'crm_company_get_rub_usdt_fixation_mode' )
				? crm_company_get_rub_usdt_fixation_mode( $company_id )
				: 'rapira_manual',
		],
	] );
}

// ════════════════════════════════════════════════════════════════════════════
// 3. ИЗМЕНИТЬ СТАТУС КОМПАНИИ
// ════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_set_company_status', 'me_ajax_set_company_status' );
function me_ajax_set_company_status(): void {
	_me_companies_root_only();

	$nonce = sanitize_text_field( wp_unslash( $_POST['_nonce'] ?? ( $_POST['nonce'] ?? '' ) ) );
	if ( ! wp_verify_nonce( $nonce, 'me_company_status' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	$company_id = (int) ( $_POST['company_id'] ?? 0 );
	$status     = sanitize_key( $_POST['status'] ?? '' );
	$reason     = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) );

	$result = crm_set_company_status( $company_id, $status, get_current_user_id(), $reason );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
	}

	$company = $result['company'] ?? null;
	if ( ! $company ) {
		wp_send_json_error( [ 'message' => 'Компания обновлена, но не удалось перечитать данные.' ], 500 );
	}

	$status_label = crm_company_status_label( (string) $company->status );

	wp_send_json_success( [
		'message'            => sprintf( 'Компания «%s»: %s.', (string) $company->name, $status_label ),
		'company_id'         => (int) $company->id,
		'status'             => (string) $company->status,
		'status_label'       => $status_label,
		'status_badge'       => crm_company_status_badge_class( (string) $company->status ),
		'blocked_at'         => (string) ( $company->blocked_at ?? '' ),
		'blocked_by_user_id' => (int) ( $company->blocked_by_user_id ?? 0 ),
		'block_reason'       => (string) ( $company->block_reason ?? '' ),
		'user_count'         => (int) ( $result['user_count'] ?? 0 ),
		'sessions_destroyed' => (int) ( $result['sessions_destroyed'] ?? 0 ),
	] );
}

// ════════════════════════════════════════════════════════════════════════════
// 4. НАЗНАЧИТЬ ПОЛЬЗОВАТЕЛЯ В КОМПАНИЮ
// ════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_assign_user_company', 'me_ajax_assign_user_company' );
function me_ajax_assign_user_company(): void {
	_me_companies_root_only();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_assign_user_company' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ] );
	}

	$user_id    = (int) ( $_POST['user_id']    ?? 0 );
	$company_id = (int) ( $_POST['company_id'] ?? 0 );

	if ( $user_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Неверный ID пользователя.' ] );
	}
	if ( crm_is_root( $user_id ) ) {
		wp_send_json_error( [ 'message' => 'Недопустимая операция.' ] );
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		wp_send_json_error( [ 'message' => 'Пользователь не найден.' ] );
	}
	if ( $company_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Обычному пользователю обязательно должна быть назначена компания.' ] );
	}

	$result = crm_assign_user_to_company( $user_id, $company_id, get_current_user_id() );
	if ( ! $result ) {
		wp_send_json_error( [ 'message' => 'Компания не найдена или недоступна.' ] );
	}

	$forced_owner_role = false;
	if ( crm_company_user_is_admin( $user_id, $company_id ) ) {
		$current_role_ids = array_map(
			static fn( $role ): int => (int) $role->id,
			crm_get_user_roles( $user_id )
		);
		$owner_role_id = crm_get_role_id_by_code( 'owner' );
		if ( $owner_role_id > 0 && ! in_array( $owner_role_id, $current_role_ids, true ) ) {
			crm_assign_roles_preserving_codes( $user_id, $current_role_ids, [ 'owner' ], get_current_user_id() );
			$forced_owner_role = true;
		}
	}

	global $wpdb;
	$company_name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM crm_companies WHERE id = %d", $company_id ) );

	crm_log( 'user.company_assigned', [
		'category'    => 'users',
		'level'       => 'info',
		'action'      => 'update',
		'message'     => "Пользователь «{$user->user_login}» назначен в компанию «{$company_name}»",
		'target_type' => 'user',
		'target_id'   => $user_id,
		'context'     => [
			'company_id'   => $company_id,
			'company_name' => $company_name,
			'assigned_by'  => get_current_user_id(),
			'forced_owner_role' => $forced_owner_role,
		],
	] );

	wp_send_json_success( [
		'message'      => $forced_owner_role
			? "Назначено в «{$company_name}». Пользователь автоматически стал owner этой компании."
			: "Назначено в «{$company_name}».",
		'company_id'   => $company_id,
		'company_name' => $company_name,
		'forced_owner_role' => $forced_owner_role,
	] );
}

// ════════════════════════════════════════════════════════════════════════════
// 5. СОЗДАТЬ ОФИС КОМПАНИИ
// ════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_create_company_office', 'me_ajax_create_company_office' );
function me_ajax_create_company_office(): void {
	_me_companies_office_creator_only();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_create_company_office' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ] );
	}

	$result = crm_create_company_office(
		[
			'company_id'   => (int) ( $_POST['company_id'] ?? 0 ),
			'name'         => wp_unslash( $_POST['name'] ?? '' ),
			'code'         => wp_unslash( $_POST['code'] ?? '' ),
			'city'         => wp_unslash( $_POST['city'] ?? '' ),
			'address_line' => wp_unslash( $_POST['address_line'] ?? '' ),
		],
		get_current_user_id()
	);

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	}

	wp_send_json_success( [
		'message' => sprintf(
			'Офис «%s» создан для компании «%s».',
			(string) $result['name'],
			(string) $result['company_name']
		),
		'office'   => $result,
	] );
}

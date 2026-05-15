<?php
/*
Template Name: Operator Telegram Page
Slug: operator-telegram
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

$current_uid = get_current_user_id();
if ( crm_is_root( $current_uid ) ) {
	malibu_exchange_render_root_company_scope_denied();
}

if ( ! crm_can_manage_operator_telegram() ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$current_company_id = crm_require_company_page_context();
$telegram_status    = crm_telegram_get_configuration_status( $current_company_id, 'operator' );
$can_invite         = crm_can_invite_operator_telegram();
$nonce_invite       = wp_create_nonce( 'me_operator_telegram_invite' );

if ( ! function_exists( 'me_operator_telegram_render_status_alert' ) ) {
	function me_operator_telegram_render_status_alert( array $status ): string {
		ob_start();

		if ( ! empty( $status['operator_ready'] ) ) :
			?>
			<div class="alert alert-success bordered m-b-20">
				<strong>Операторский бот готов.</strong><br>
				Можно создавать invite-ссылки для привязки CRM-пользователей этой компании.
			</div>
			<?php
		elseif ( ! empty( $status['is_configured'] ) ) :
			?>
			<div class="alert alert-warning bordered m-b-20">
				<strong>Создание новых Telegram invite-ссылок недоступно.</strong><br>
				<?php echo esc_html( (string) ( $status['blocked_reason'] ?? 'Откройте настройки компании и подключите callback операторского бота.' ) ); ?>
			</div>
			<?php
		else :
			?>
			<div class="alert alert-danger bordered m-b-20">
				<strong>Создание Telegram invite-ссылок заблокировано.</strong><br>
				Сначала заполните имя и токен операторского Telegram-бота в настройках компании.
			</div>
			<?php
		endif;

		return (string) ob_get_clean();
	}
}

get_header();
?>

<?php get_template_part( 'template-parts/sidebar' ); ?>

<div class="page-container">
	<?php get_template_part( 'template-parts/header-backoffice' ); ?>

	<div class="page-content-wrapper">
		<div class="content">
			<div class="jumbotron" data-pages="parallax">
				<div class="container-fluid container-fixed-lg sm-p-l-0 sm-p-r-0">
					<div class="inner">
						<ol class="breadcrumb">
							<li class="breadcrumb-item"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a></li>
							<li class="breadcrumb-item active">Операторы Telegram</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">
				<div class="m-b-15">
					<h3 class="m-b-5">Операторы Telegram</h3>
					<p class="hint-text m-b-0">
						Привязка существующих CRM-пользователей компании к отдельному операторскому Telegram-боту.
					</p>
				</div>

				<div id="operator-invite-page-status-host">
					<?php echo me_operator_telegram_render_status_alert( $telegram_status ); ?>
				</div>
				<div id="operator-invite-page-alert" class="alert d-none m-b-15" role="alert"></div>

				<div class="card card-default m-b-20">
					<div class="card-body p-t-20 p-b-15">
						<div class="row g-2 align-items-center m-b-10">
							<div class="col-12 col-md-4 col-lg-3">
								<div class="input-group">
									<span class="input-group-text"><i class="pg-icon">search</i></span>
									<input type="search" id="of-search" class="form-control" placeholder="chat_id, username, имя, email">
								</div>
							</div>
							<div class="col-6 col-md-2">
								<select id="of-status" class="full-width" data-init-plugin="select2">
									<option value="">Все статусы</option>
									<?php foreach ( crm_operator_telegram_user_list_statuses() as $status_code => $label ) : ?>
										<option value="<?php echo esc_attr( $status_code ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-6 col-md-2">
								<input type="date" id="of-date-from" class="form-control" placeholder="Дата с">
							</div>
							<div class="col-6 col-md-2">
								<input type="date" id="of-date-to" class="form-control" placeholder="Дата по">
							</div>
						</div>

						<div class="row g-2 align-items-center">
							<div class="col-4 col-md-1">
								<select id="of-per-page" class="full-width" data-init-plugin="select2">
									<option value="25">25</option>
									<option value="50">50</option>
									<option value="100">100</option>
								</select>
							</div>
							<div class="col-8 col-md-3 d-flex gap-2">
								<button type="button" id="btn-operator-users-search" class="btn btn-primary">
									<i class="pg-icon">search</i> Найти
								</button>
								<button type="button" id="btn-operator-users-reset" class="btn btn-default">
									Сброс
								</button>
							</div>
						</div>
					</div>
				</div>

				<div class="d-flex justify-content-between align-items-center m-b-10">
					<div id="operator-users-stats" class="text-muted small"></div>
					<div id="operator-users-loading" class="text-muted small d-none">
						<span class="pg-icon" style="animation:spin 1s linear infinite;display:inline-block;">refresh</span>
						Загрузка…
					</div>
				</div>

				<div class="card card-default m-b-30">
					<div class="card-body p-0">
						<div class="table-responsive">
							<table class="table table-hover m-b-0" id="operator-users-table">
								<thead>
									<tr>
										<th style="width:60px">#</th>
										<th style="width:240px">Оператор</th>
										<th style="width:150px">chat_id</th>
										<th style="width:170px">Telegram</th>
										<th style="width:120px">Статус</th>
										<th style="width:190px">Роль</th>
										<th style="width:180px">Последний инвайт</th>
										<th style="width:160px">Привязан</th>
										<th style="width:210px" class="text-right">Действия</th>
									</tr>
								</thead>
								<tbody id="operator-users-tbody">
									<tr>
										<td colspan="9" class="text-center p-t-30 p-b-30 text-muted">
											Нажмите «Найти» для загрузки данных.
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<div id="operator-users-pagination" class="d-flex justify-content-between align-items-center m-t-15 m-b-30"></div>

				<?php if ( $can_invite ) : ?>
				<div class="card card-default m-b-30" id="operator-invite-history-card">
					<div class="card-header d-flex justify-content-between align-items-center">
						<div class="card-title">История Telegram-инвайтов</div>
						<div class="d-flex align-items-center gap-2">
							<div id="operator-invite-history-focus-meta" class="hint-text fs-12">Все invite-ссылки компании</div>
							<button type="button" id="btn-operator-invite-history-clear-user" class="btn btn-default btn-xs d-none">Снять фокус</button>
						</div>
					</div>
					<div class="card-body">
						<div class="row g-2 align-items-center m-b-15">
							<div class="col-md-4">
								<input type="search" id="operator-invite-history-search" class="form-control" placeholder="payload, chat_id, username, имя, email">
							</div>
							<div class="col-md-2">
								<select id="operator-invite-history-status" class="full-width" data-init-plugin="select2">
									<option value="">Все статусы</option>
									<?php foreach ( crm_operator_telegram_invite_statuses() as $status_code => $label ) : ?>
										<option value="<?php echo esc_attr( $status_code ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-2">
								<input type="date" id="operator-invite-history-date-from" class="form-control">
							</div>
							<div class="col-md-2">
								<input type="date" id="operator-invite-history-date-to" class="form-control">
							</div>
							<div class="col-md-2">
								<select id="operator-invite-history-per-page" class="full-width" data-init-plugin="select2">
									<option value="25">25</option>
									<option value="50">50</option>
									<option value="100">100</option>
								</select>
							</div>
						</div>
						<div class="d-flex justify-content-between align-items-center m-b-15">
							<div id="operator-invite-history-stats" class="text-muted small">Нажмите «Найти», чтобы загрузить историю invite-ссылок.</div>
							<div class="d-flex align-items-center gap-2">
								<button type="button" id="btn-operator-invite-history-reset" class="btn btn-default btn-sm">Сброс</button>
								<button type="button" id="btn-operator-invite-history-search" class="btn btn-primary btn-sm">
									<i class="pg-icon m-r-5">search</i>Найти
								</button>
							</div>
						</div>

						<div class="table-responsive">
							<table class="table table-hover m-b-0">
								<thead>
									<tr>
										<th style="width:70px">#</th>
										<th style="width:140px">Создал</th>
										<th>Оператор / chat_id</th>
										<th style="width:160px">Payload</th>
										<th style="width:110px">Статус</th>
										<th style="width:120px">TTL</th>
										<th style="width:160px">Активен до</th>
										<th style="width:160px">Создан</th>
										<th style="width:190px" class="text-center">Действия</th>
									</tr>
								</thead>
								<tbody id="operator-invite-history-tbody">
									<tr>
										<td colspan="9" class="text-center text-muted p-t-25 p-b-25">Нажмите «Найти», чтобы загрузить историю invite-ссылок.</td>
									</tr>
								</tbody>
							</table>
						</div>
						<div class="d-flex justify-content-end align-items-center m-t-10">
							<div id="operator-invite-history-pagination"></div>
						</div>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>

		<?php get_template_part( 'template-parts/footer-backoffice' ); ?>
	</div>
</div>

<?php get_template_part( 'template-parts/toast-host' ); ?>

<div class="modal fade" id="operator-telegram-invite-modal" tabindex="-1" role="dialog" aria-labelledby="operator-telegram-invite-title" aria-hidden="true">
	<div class="modal-dialog modal-lg" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title" id="operator-telegram-invite-title">Telegram-инвайты операторов</h4>
				<button type="button" class="close" data-bs-dismiss="modal" aria-label="Закрыть">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="modal-body">
				<div id="operator-invite-alert" class="alert d-none m-b-15" role="alert"></div>
				<div id="operator-invite-modal-status-host" class="m-b-15">
					<?php echo me_operator_telegram_render_status_alert( $telegram_status ); ?>
				</div>

				<div class="card card-default m-b-20 operator-invite-create-card">
					<div class="card-header">
						<div class="card-title">Создать Telegram-инвайт</div>
					</div>
					<div class="card-body">
						<form id="operator-invite-create-form">
							<div class="operator-invite-create-grid">
								<div class="operator-invite-user-field">
									<div class="form-group">
										<label for="operator-invite-user-display">CRM-пользователь</label>
										<input type="hidden" id="operator-invite-user-id" value="">
										<input type="text" id="operator-invite-user-display" class="form-control" value="Выберите пользователя в таблице" readonly>
									</div>
								</div>
								<div class="operator-invite-submit-field">
									<button type="button" id="btn-create-operator-invite" class="btn btn-primary btn-block" <?php echo empty( $telegram_status['operator_ready'] ) ? 'disabled' : ''; ?>>
										<i class="pg-icon m-r-5">link</i>Создать инвайт
									</button>
								</div>
							</div>
						</form>
					</div>
				</div>

				<div id="operator-invite-preview" class="card card-default m-b-20 operator-invite-preview-card d-none">
					<div class="card-header">
						<div class="card-title">Последний созданный инвайт</div>
					</div>
					<div class="card-body">
						<div class="operator-invite-preview-grid">
							<div class="operator-invite-preview-main">
								<div class="form-group">
									<label for="operator-invite-link-preview">Ссылка</label>
									<div class="input-group operator-invite-link-group">
										<input type="text" id="operator-invite-link-preview" class="form-control" readonly>
										<span class="input-group-btn">
											<button type="button" id="btn-copy-operator-invite-link" class="btn btn-default">Копировать</button>
										</span>
									</div>
									<p class="hint-text m-t-5" id="operator-invite-preview-meta">—</p>
								</div>
							</div>
							<div class="operator-invite-qr-column">
								<div class="operator-invite-qr-panel text-center">
									<div class="fs-12 semi-bold m-b-10 text-uppercase" style="letter-spacing:.08em;color:#3b5998">Telegram Invite QR</div>
									<div id="operator-invite-qr-preview-wrap" class="m-b-10"></div>
									<div class="hint-text fs-12">Покажите QR-код пользователю или отправьте готовую ссылку.</div>
								</div>
							</div>
						</div>
					</div>
				</div>

			</div>
		</div>
	</div>
</div>

<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>

<style>
#operator-telegram-invite-modal .modal-dialog {
	max-width: 860px;
	width: calc(100% - 32px);
}
#operator-telegram-invite-modal .modal-content {
	border: 0;
	border-radius: 6px;
	box-shadow: 0 18px 48px rgba(33, 33, 33, .22);
}
#operator-telegram-invite-modal .modal-header {
	padding: 28px 32px 8px;
	border-bottom: 0;
}
#operator-telegram-invite-modal .modal-title {
	font-size: 24px;
	line-height: 1.25;
	font-weight: 500;
}
#operator-telegram-invite-modal .modal-body {
	padding: 0 32px 34px;
}
#operator-telegram-invite-modal #operator-invite-modal-status-host {
	margin-bottom: 28px;
}
#operator-telegram-invite-modal #operator-invite-modal-status-host .alert {
	margin-bottom: 0;
}
#operator-telegram-invite-modal .operator-invite-create-card,
#operator-telegram-invite-modal .operator-invite-preview-card {
	border: 0;
	box-shadow: none;
	background: transparent;
	margin-bottom: 34px;
}
#operator-telegram-invite-modal .operator-invite-create-card .card-header,
#operator-telegram-invite-modal .operator-invite-preview-card .card-header {
	padding: 0 0 12px;
	min-height: 0;
	border-bottom: 0;
	background: transparent;
}
#operator-telegram-invite-modal .operator-invite-create-card .card-title,
#operator-telegram-invite-modal .operator-invite-preview-card .card-title {
	float: none;
	display: block;
	margin: 0;
	font-size: 12px;
	line-height: 1.3;
	font-weight: 600;
	letter-spacing: .08em;
	text-transform: uppercase;
	color: #2f3438;
}
#operator-telegram-invite-modal .operator-invite-create-card .card-body,
#operator-telegram-invite-modal .operator-invite-preview-card .card-body {
	padding: 0;
}
#operator-telegram-invite-modal .operator-invite-create-grid {
	display: grid;
	grid-template-columns: minmax(360px, 1fr) 224px;
	column-gap: 24px;
	align-items: end;
}
#operator-telegram-invite-modal .operator-invite-create-grid .form-group {
	margin-bottom: 0;
}
#operator-telegram-invite-modal label {
	margin-bottom: 6px;
	font-weight: 500;
	color: #555;
}
#operator-telegram-invite-modal #operator-invite-user-display,
#operator-telegram-invite-modal #btn-create-operator-invite {
	height: 46px;
}
#operator-telegram-invite-modal #btn-create-operator-invite {
	width: 100%;
	white-space: nowrap;
}
#operator-telegram-invite-modal .operator-invite-preview-grid {
	display: grid;
	grid-template-columns: minmax(390px, 1fr) 268px;
	column-gap: 32px;
	align-items: start;
}
#operator-telegram-invite-modal .operator-invite-preview-main {
	min-width: 0;
	padding-top: 1px;
}
#operator-telegram-invite-modal .operator-invite-preview-main .form-group {
	margin-bottom: 0;
}
#operator-telegram-invite-modal .operator-invite-link-group {
	display: grid !important;
	grid-template-columns: minmax(0, 1fr) 132px;
	width: 100%;
	align-items: stretch;
}
#operator-telegram-invite-modal .operator-invite-link-group .form-control {
	min-width: 0;
	width: 100%;
	height: 46px;
}
#operator-telegram-invite-modal .operator-invite-link-group .input-group-btn {
	display: block;
	width: 132px;
	white-space: nowrap;
}
#operator-telegram-invite-modal .operator-invite-link-group .btn {
	width: 100%;
	height: 46px;
	border-top-left-radius: 0;
	border-bottom-left-radius: 0;
}
#operator-telegram-invite-modal #operator-invite-preview-meta {
	margin-top: 12px;
	min-height: 44px;
	line-height: 1.45;
	word-break: break-word;
}
#operator-telegram-invite-modal .operator-invite-qr-column {
	display: flex;
	justify-content: flex-end;
}
#operator-telegram-invite-modal .operator-invite-qr-panel {
	width: 268px;
	min-height: 330px;
	padding: 14px;
	background: #f7fafc;
	border: 1px solid #e8edf2;
	border-radius: 12px;
	display: flex;
	flex-direction: column;
	align-items: center;
}
#operator-telegram-invite-modal #operator-invite-qr-preview-wrap {
	width: 232px;
	height: 232px;
	display: flex;
	align-items: center;
	justify-content: center;
}
#operator-telegram-invite-modal #operator-invite-qr-preview-wrap img {
	width: 232px !important;
	height: 232px !important;
	object-fit: contain;
	background: #fff;
	border: 1px solid #e8edf2;
	border-radius: 8px;
	padding: 8px;
}
@media (max-width: 767px) {
	#operator-telegram-invite-modal .modal-dialog {
		width: calc(100% - 20px);
	}
	#operator-telegram-invite-modal .modal-header {
		padding: 22px 22px 8px;
	}
	#operator-telegram-invite-modal .modal-body {
		padding: 0 22px 26px;
	}
	#operator-telegram-invite-modal .operator-invite-create-grid,
	#operator-telegram-invite-modal .operator-invite-preview-grid {
		grid-template-columns: minmax(0, 1fr);
		row-gap: 18px;
	}
	#operator-telegram-invite-modal .operator-invite-qr-column {
		justify-content: flex-start;
	}
	#operator-telegram-invite-modal .operator-invite-qr-panel {
		width: 100%;
	}
	#operator-telegram-invite-modal .operator-invite-link-group {
		grid-template-columns: minmax(0, 1fr) 122px;
	}
	#operator-telegram-invite-modal .operator-invite-link-group .input-group-btn {
		width: 122px;
	}
}
#operator-users-table {
	border-collapse: separate;
	border-spacing: 0;
}
#operator-users-table th {
	font-size: 11px;
	letter-spacing: .06em;
	color: #626a70;
	font-weight: 600;
	padding-top: 16px;
	padding-bottom: 16px;
}
#operator-users-table td {
	vertical-align: middle;
	padding-top: 14px;
	padding-bottom: 14px;
	border-top-color: #eef1f4;
}
#operator-users-table .operator-user-row:hover td {
	background: #fbfcfd;
}
.operator-avatar {
	width: 44px;
	height: 44px;
	min-width: 44px;
	max-width: 44px;
	border-radius: 50%;
	overflow: hidden;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 44px;
	line-height: 44px;
	box-shadow: 0 0 0 2px #fff, 0 1px 4px rgba(31, 45, 61, .12);
}
.operator-avatar img {
	width: 44px !important;
	height: 44px !important;
	min-width: 44px;
	min-height: 44px;
	border-radius: 50%;
	object-fit: cover;
	display: block;
}
.operator-avatar-fallback {
	background: #0f75bc;
	color: #fff;
	font-weight: 700;
	font-size: 13px;
	letter-spacing: 0;
}
.operator-person {
	display: flex;
	align-items: center;
	min-width: 0;
}
.operator-person-body {
	min-width: 0;
}
.operator-name,
.operator-role-name,
.operator-inline-value {
	font-size: 14px;
	line-height: 1.25;
	color: #2f3438;
	font-weight: 600;
}
.operator-meta,
.operator-date {
	font-size: 12px;
	line-height: 1.35;
	color: #7b8288;
}
.operator-code-tag {
	display: inline-flex;
	align-items: center;
	min-height: 28px;
	padding: 4px 9px;
	border-radius: 6px;
	background: #f4f6f8;
	color: #4a5258;
	font-family: "Montserrat", Arial, sans-serif;
	font-size: 13px;
	letter-spacing: .01em;
}
.operator-status-chip {
	display: inline-flex;
	align-items: center;
	min-height: 24px;
	padding: 4px 9px;
	border-radius: 12px;
	font-size: 12px;
	line-height: 1;
	font-weight: 600;
	white-space: nowrap;
}
.operator-inline-cell {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: 32px;
	white-space: nowrap;
}
.operator-crm-state {
	font-size: 11px;
	line-height: 1;
	color: #8a9299;
	padding-left: 8px;
	border-left: 1px solid #e6eaee;
}
.operator-crm-state-active {
	color: #0d9e82;
}
.operator-invite-info-trigger {
	width: 24px;
	height: 24px;
	min-width: 24px;
	padding: 0;
	color: #7b8288;
}
.operator-invite-info-trigger .pg-icon {
	font-size: 13px;
	line-height: 1;
}
.operator-invite-info-trigger:hover,
.operator-invite-info-trigger:focus {
	color: #2f3438;
}
#operator-users-table .btn-group-sm > .btn {
	min-height: 32px;
	font-weight: 600;
}
#operator-users-table .dropdown-menu {
	min-width: 268px;
	padding: 8px 0;
}
#operator-users-table .dropdown-divider {
	margin: 6px 0;
}
#operator-users-table .operator-action-item {
	display: flex;
	align-items: center;
	gap: 12px;
	min-height: 42px;
	padding: 9px 18px;
	line-height: 1.2;
	white-space: nowrap;
}
#operator-users-table .operator-action-icon {
	width: 22px;
	min-width: 22px;
	text-align: center;
	font-size: 18px;
	line-height: 1;
	color: #4e555b;
}
#operator-users-table .operator-action-item span {
	display: block;
	min-width: 0;
}
.operator-invite-actions .dropdown-item.operator-invite-action-item {
	display: flex;
	align-items: center;
	gap: 8px;
}
.operator-invite-actions .operator-invite-action-icon {
	font-size: 15px;
	width: 16px;
	text-align: center;
}
</style>

<?php
add_action(
	'wp_footer',
	function () use ( $nonce_invite, $telegram_status, $can_invite ) {
		?>
<script>
(function($){
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCE = '<?php echo esc_js( $nonce_invite ); ?>';
	var CAN_INVITE = <?php echo $can_invite ? 'true' : 'false'; ?>;
	var OPERATOR_INVITE_STATUS = <?php echo crm_json_for_inline_js( $telegram_status ); ?> || {};
	var OPERATOR_USER_STATUS_LABELS = <?php echo crm_json_for_inline_js( crm_operator_telegram_user_list_statuses() ); ?> || {};
	var OPERATOR_INVITE_STATUS_LABELS = <?php echo crm_json_for_inline_js( crm_operator_telegram_invite_statuses() ); ?> || {};
	var OPERATOR_INVITE_STATUS_BADGES = { new: 'primary', used: 'success', expired: 'warning', revoked: 'secondary' };

	var currentOperatorPage = 1;
	var currentInvitePage = 1;
	var currentInviteUserId = 0;
	var currentInviteUserName = '';
	var latestOperatorInvite = null;
	var operatorInviteHistoryMap = {};
	var operatorInviteServerOffsetMs = 0;

	function escHtml(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	function showToast(message, type) {
		if (window.MalibuToast && typeof window.MalibuToast.show === 'function') {
			window.MalibuToast.show(message, type || 'info');
			return;
		}
		if (window.console && console.warn) {
			console.warn(message);
		}
	}

	function showConfirm(message, callback, options) {
		if (window.MalibuConfirm && typeof window.MalibuConfirm.show === 'function') {
			window.MalibuConfirm.show(message, callback, options || {});
			return;
		}
		showToast('Не удалось открыть окно подтверждения. Обновите страницу и повторите действие.', 'danger');
	}

	function showInlineAlert($el, message, type) {
		$el.removeClass('d-none alert-success alert-danger alert-warning alert-info')
			.addClass('alert-' + (type || 'info'))
			.text(message);
	}

	function hideInlineAlert($el) {
		$el.addClass('d-none').removeClass('alert-success alert-danger alert-warning alert-info').text('');
	}

	function copyText(link, successMessage) {
		if (!link) return;
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(String(link)).then(function () {
				showToast(successMessage || 'Скопировано.', 'success');
			});
			return;
		}
		showToast('Скопируйте вручную: ' + link, 'info');
	}

	function operatorAvatarInitials(label) {
		return (label || 'OP').replace(/\s+/g, ' ').trim().substring(0, 2).toUpperCase() || 'OP';
	}

	function operatorAvatarHtml(url, label) {
		if (url) {
			return '<span class="operator-avatar m-r-10">'
				+ '<img src="' + escHtml(url) + '" data-src="' + escHtml(url) + '" data-src-retina="' + escHtml(url) + '" alt="' + escHtml(label || 'Profile Image') + '">'
				+ '</span>';
		}
		return '<span class="operator-avatar operator-avatar-fallback m-r-10">'
			+ escHtml(operatorAvatarInitials(label))
			+ '</span>';
	}

	function operatorInviteStatusHtml(status) {
		var missingLabels = $.map(status.missing_fields || [], function(item) {
			return item && item.label ? item.label : null;
		});

		if (status.operator_ready) {
			return '<div class="alert alert-success bordered m-b-0"><strong>Создание Telegram invite-ссылок доступно.</strong><br>'
				+ (status.bot_handle ? 'Бот: ' + escHtml(status.bot_handle) + '. ' : '')
				+ 'Можно создавать deep-link, показывать QR и ждать запуск /start от оператора.</div>';
		}

		if (status.is_configured) {
			var html = '<div class="alert alert-warning bordered m-b-0"><strong>Создание новых Telegram invite-ссылок недоступно.</strong><br>'
				+ escHtml(status.blocked_reason || 'Сначала подключите callback для этой компании.');
			if (status.webhook_last_error) {
				html += '<div class="m-t-10"><strong>Последняя ошибка Telegram API:</strong> ' + escHtml(status.webhook_last_error) + '</div>';
			}
			html += '<div class="m-t-10">Откройте «Настройки», проверьте имя бота и токен, затем подключите callback операторского бота.</div></div>';
			return html;
		}

		var danger = '<div class="alert alert-danger bordered m-b-0"><strong>Создание Telegram invite-ссылок заблокировано.</strong><br>'
			+ 'Для создания ссылки заполните настройки операторского Telegram-бота: имя бота и токен. Перейдите в «Настройки».';
		if (missingLabels.length) {
			danger += '<div class="m-t-10"><strong>Не заполнено:</strong> ' + escHtml(missingLabels.join(', ')) + '.</div>';
		}
		danger += '</div>';
		return danger;
	}

	function renderOperatorInviteStatus(status) {
		OPERATOR_INVITE_STATUS = status || {};
		var html = operatorInviteStatusHtml(OPERATOR_INVITE_STATUS);
		$('#operator-invite-page-status-host, #operator-invite-modal-status-host').html(html);
		$('#btn-create-operator-invite').prop('disabled', !OPERATOR_INVITE_STATUS.operator_ready);
		$('.js-open-operator-invite-modal')
			.prop('disabled', !OPERATOR_INVITE_STATUS.operator_ready)
			.attr('title', OPERATOR_INVITE_STATUS.operator_ready ? '' : (OPERATOR_INVITE_STATUS.blocked_reason || 'Сначала подключите операторский Telegram callback в настройках.'));
	}

	function collectOperatorUserFilters() {
		return {
			action: 'me_operator_telegram_users_list',
			_nonce: NONCE,
			page: currentOperatorPage,
			per_page: $('#of-per-page').val(),
			search: $('#of-search').val(),
			status: $('#of-status').val(),
			date_from: $('#of-date-from').val(),
			date_to: $('#of-date-to').val()
		};
	}

	function renderOperatorUsersPagination(totalPages, current) {
		var $wrap = $('#operator-users-pagination').empty();
		if (totalPages <= 1) return;

		var html = '<ul class="pagination pagination-sm no-margin">';
		html += '<li class="page-item' + (current <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (current - 1) + '">&laquo;</a></li>';
		for (var i = 1; i <= totalPages; i++) {
			html += '<li class="page-item' + (i === current ? ' active' : '') + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
		}
		html += '<li class="page-item' + (current >= totalPages ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (current + 1) + '">&raquo;</a></li>';
		html += '</ul>';
		$wrap.html(html);
	}

	function renderOperatorUserActions(row) {
		if (!CAN_INVITE) {
			return '<span class="text-muted">Нет прав</span>';
		}

		var disabled = OPERATOR_INVITE_STATUS.operator_ready ? '' : ' disabled';
		var title = OPERATOR_INVITE_STATUS.operator_ready ? '' : (OPERATOR_INVITE_STATUS.blocked_reason || 'Сначала подключите операторский Telegram callback в настройках.');
		var userName = row.name || row.user_login || ('User #' + row.user_id);
		var isLinked = row.telegram_status && row.telegram_status !== 'not_linked';
		var primary = isLinked
			? '<button type="button" class="btn btn-default js-focus-operator-invite-history" data-user-id="' + escHtml(row.user_id) + '" data-user-name="' + escHtml(userName) + '">История</button>'
			: '<button type="button" class="btn btn-default js-open-operator-invite-modal" data-user-id="' + escHtml(row.user_id) + '" data-user-name="' + escHtml(userName) + '" title="' + escHtml(title) + '"' + disabled + '>Инвайт</button>';
		var menu = '';
		if (isLinked && row.chat_id) {
			menu += '<a href="#" class="dropdown-item operator-action-item js-copy-operator-chat-id" data-chat-id="' + escHtml(row.chat_id) + '"><i class="pg-icon operator-action-icon">copy</i><span>Скопировать chat_id</span></a>';
			menu += '<a href="#" class="dropdown-item operator-action-item js-refresh-operator-profile" data-user-id="' + escHtml(row.user_id) + '"><i class="pg-icon operator-action-icon">refresh</i><span>Обновить аватар</span></a>';
			menu += '<div class="dropdown-divider"></div>';
		}
		menu += '<a href="#" class="dropdown-item operator-action-item js-focus-operator-invite-history" data-user-id="' + escHtml(row.user_id) + '" data-user-name="' + escHtml(userName) + '"><i class="pg-icon operator-action-icon">time</i><span>История инвайтов</span></a>';
		menu += '<a href="#" class="dropdown-item operator-action-item js-open-operator-invite-modal' + (OPERATOR_INVITE_STATUS.operator_ready ? '' : ' disabled') + '" data-user-id="' + escHtml(row.user_id) + '" data-user-name="' + escHtml(userName) + '" title="' + escHtml(title) + '"><i class="pg-icon operator-action-icon">link</i><span>Создать новый инвайт</span></a>';

		return ''
			+ '<div class="btn-group btn-group-sm">'
			+ primary
			+ '<button type="button" class="btn btn-default dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false"></button>'
			+ '<div class="dropdown-menu dropdown-menu-right">'
			+ menu
			+ '</div></div>';
	}

	function renderOperatorUsersTable(rows) {
		var $tbody = $('#operator-users-tbody').empty();
		if (!rows || !rows.length) {
			$tbody.html('<tr><td colspan="9" class="text-center p-t-30 p-b-30 text-muted">Ничего не найдено.</td></tr>');
			return;
		}

			rows.forEach(function(row) {
				var userName = row.name || row.user_login || ('User #' + row.user_id);
				var profileLine = $.trim([row.telegram_first_name || '', row.telegram_last_name || ''].join(' '));
				var crmStatus = (row.crm_status || '').toLowerCase();
				var crmStatusHtml = crmStatus === 'active'
					? '<span class="operator-crm-state operator-crm-state-active">CRM: активен</span>'
					: '<span class="operator-crm-state">CRM ' + escHtml(row.crm_status_label || row.crm_status || '—') + '</span>';
				var nameHtml = '<div class="operator-person">'
				+ operatorAvatarHtml(row.telegram_avatar_url || '', userName || profileLine || row.telegram_username || 'OP')
				+ '<div class="operator-person-body"><div class="operator-name">' + escHtml(userName) + '</div>'
				+ '<div class="operator-meta">ID #' + escHtml(row.user_id) + (row.user_email ? ' · ' + escHtml(row.user_email) : '') + '</div></div></div>';
				var chatHtml = row.chat_id ? '<span class="operator-code-tag">' + escHtml(row.chat_id) + '</span>' : '<span class="hint-text">—</span>';
				var tgTitle = profileLine ? ' title="' + escHtml(profileLine) + '"' : '';
				var tgHtml = row.telegram_username
					? '<span class="operator-inline-value"' + tgTitle + '>@' + escHtml(row.telegram_username) + (profileLine ? ' · ' + escHtml(profileLine) : '') + '</span>'
					: '<span class="hint-text">—</span>';
				var statusHtml = '<span class="operator-status-chip badge-' + escHtml(row.telegram_status_badge || 'secondary') + '">'
					+ escHtml(row.telegram_status_label || OPERATOR_USER_STATUS_LABELS[row.telegram_status] || row.telegram_status || '—')
					+ '</span>';
				var roleHtml = '<div class="operator-inline-cell">'
					+ '<span class="operator-role-name">' + escHtml(row.roles_label || 'Без CRM-роли') + '</span>'
					+ crmStatusHtml
					+ '</div>';
				var inviteHtml = '<span class="hint-text">Не выдавался</span>';
				if (row.last_invite_id) {
					var inviteTitle = 'Invite #' + row.last_invite_id;
					var inviteMeta = inviteTitle;
					if (row.last_invite_status === 'new' && row.last_invite_expires_at) {
						inviteMeta += ' · активен до ' + row.last_invite_expires_at;
					} else if (row.last_invite_status === 'used' && row.last_invite_used_at) {
						inviteMeta += ' · использован ' + row.last_invite_used_at;
					} else if (row.last_invite_created_at) {
						inviteMeta += ' · создан ' + row.last_invite_created_at;
					}
					inviteHtml = '<div class="operator-inline-cell">'
					+ '<span class="operator-status-chip badge-' + escHtml(row.last_invite_status_badge || 'secondary') + '">'
					+ escHtml(row.last_invite_status_label || row.last_invite_status || '—')
					+ '</span>'
					+ '<button type="button" class="btn btn-icon-link operator-invite-info-trigger"'
					+ ' data-bs-toggle="popover"'
					+ ' data-bs-trigger="focus"'
					+ ' data-bs-placement="top"'
					+ ' data-bs-container="body"'
					+ ' data-bs-title="' + escHtml(inviteTitle) + '"'
					+ ' data-bs-content="' + escHtml(inviteMeta) + '"'
					+ ' aria-label="' + escHtml(inviteMeta) + '"><i class="pg-icon">info</i></button>'
					+ '</div>';
				}

				var linkedHtml = row.linked_at
					? '<span class="operator-date">' + escHtml(row.linked_at) + '</span>'
					: '<span class="hint-text">—</span>';
				var html = '<tr class="operator-user-row" id="operator-telegram-row-' + escHtml(row.user_id) + '">'
				+ '<td class="v-align-middle"><span class="hint-text fs-12">#' + escHtml(row.user_id) + '</span></td>'
				+ '<td class="v-align-middle">' + nameHtml + '</td>'
				+ '<td class="v-align-middle">' + chatHtml + '</td>'
				+ '<td class="v-align-middle">' + tgHtml + '</td>'
				+ '<td class="v-align-middle">' + statusHtml + '</td>'
				+ '<td class="v-align-middle">' + roleHtml + '</td>'
				+ '<td class="v-align-middle">' + inviteHtml + '</td>'
				+ '<td class="v-align-middle">' + linkedHtml + '</td>'
				+ '<td class="v-align-middle text-right">' + renderOperatorUserActions(row) + '</td>'
				+ '</tr>';

			$tbody.append(html);
		});

		$('#operator-users-tbody [data-bs-toggle="dropdown"]').each(function() {
			if (window.bootstrap && bootstrap.Dropdown) {
				bootstrap.Dropdown.getOrCreateInstance(this, {
					popperConfig: function(config) {
						return $.extend(true, config, { strategy: 'fixed' });
					}
				});
			}
		});
		$('#operator-users-tbody [data-bs-toggle="popover"]').each(function() {
			if (window.bootstrap && bootstrap.Popover) {
				bootstrap.Popover.getOrCreateInstance(this, {
					container: 'body',
					trigger: 'focus',
					placement: 'top'
				});
			}
		});
	}

	function loadOperatorUsers(page) {
		currentOperatorPage = page || 1;
		var payload = collectOperatorUserFilters();
		payload.page = currentOperatorPage;
		$('#operator-users-loading').removeClass('d-none');
		hideInlineAlert($('#operator-invite-page-alert'));

		$.post(AJAX_URL, payload, function(res) {
			$('#operator-users-loading').addClass('d-none');
			if (!res || !res.success) {
				showInlineAlert($('#operator-invite-page-alert'), (res && res.data && res.data.message) || 'Не удалось загрузить операторов.', 'danger');
				return;
			}
			if (res.data.telegram_status) {
				renderOperatorInviteStatus(res.data.telegram_status);
			}
			renderOperatorUsersTable(res.data.rows || []);
			renderOperatorUsersPagination(res.data.total_pages || 1, res.data.page || 1);
			$('#operator-users-stats').text('Найдено: ' + (res.data.total || 0));
		}, 'json').fail(function() {
			$('#operator-users-loading').addClass('d-none');
			showInlineAlert($('#operator-invite-page-alert'), 'Ошибка сервера при загрузке операторов.', 'danger');
		});
	}

	function resetOperatorInvitePreview() {
		latestOperatorInvite = null;
		$('#operator-invite-preview').addClass('d-none');
		$('#operator-invite-link-preview').val('');
		$('#operator-invite-preview-meta').text('—');
		$('#operator-invite-qr-preview-wrap').html('');
	}

	function renderOperatorInvitePreview(invite) {
		if (!invite || !invite.invite_url) {
			resetOperatorInvitePreview();
			return;
		}

		latestOperatorInvite = invite;
		$('#operator-invite-preview').removeClass('d-none');
		$('#operator-invite-link-preview').val(invite.invite_url || '');
		$('#operator-invite-preview-meta').text('Payload: ' + (invite.telegram_start_payload || '—') + ' · Активен до: ' + (invite.expires_at || '—'));
		$('#operator-invite-qr-preview-wrap').html(
			invite.qr_url
				? '<img src="' + escHtml(invite.qr_url) + '" alt="">'
				: '<div class="hint-text">QR ещё не готов.</div>'
		);
	}

	function openOperatorInviteModal(userId, userName) {
		hideInlineAlert($('#operator-invite-alert'));
		resetOperatorInvitePreview();
		userId = parseInt(userId, 10) || 0;
		if (!userId) {
			showToast('Выберите CRM-пользователя в таблице.', 'warning');
			return;
		}
		$('#operator-invite-user-id').val(String(userId));
		$('#operator-invite-user-display').val(($.trim(String(userName || '')) || ('User #' + userId)) + ' · ID ' + userId);
		$('#operator-telegram-invite-modal').modal('show');
	}

	function renderOperatorInviteHistoryFocus() {
		var hasFocus = currentInviteUserId > 0;
		$('#btn-operator-invite-history-clear-user').toggleClass('d-none', !hasFocus);
		$('#operator-invite-history-focus-meta').text(
			hasFocus
				? 'Фокус на операторе: ' + (currentInviteUserName || ('User #' + currentInviteUserId))
				: 'Все invite-ссылки компании'
		);
	}

	function clearOperatorInviteHistoryFocus() {
		currentInviteUserId = 0;
		currentInviteUserName = '';
		renderOperatorInviteHistoryFocus();
	}

	function focusOperatorInviteHistoryOnUser(id, name) {
		currentInviteUserId = parseInt(id, 10) || 0;
		currentInviteUserName = $.trim(String(name || '')) || ('User #' + currentInviteUserId);
		renderOperatorInviteHistoryFocus();
		loadOperatorInviteHistory(1);

		var card = document.getElementById('operator-invite-history-card');
		if (card && typeof card.scrollIntoView === 'function') {
			card.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	}

	function collectOperatorInviteHistoryFilters() {
		return {
			action: 'me_operator_telegram_invites_history',
			_nonce: NONCE,
			page: currentInvitePage,
			user_id: currentInviteUserId || '',
			per_page: $('#operator-invite-history-per-page').val(),
			search: $('#operator-invite-history-search').val(),
			status: $('#operator-invite-history-status').val(),
			date_from: $('#operator-invite-history-date-from').val(),
			date_to: $('#operator-invite-history-date-to').val()
		};
	}

	function renderOperatorInviteHistoryPagination(totalPages, current) {
		var $wrap = $('#operator-invite-history-pagination').empty();
		if (totalPages <= 1) return;

		var html = '<ul class="pagination pagination-sm no-margin">';
		html += '<li class="page-item' + (current <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (current - 1) + '">&laquo;</a></li>';
		for (var i = 1; i <= totalPages; i++) {
			html += '<li class="page-item' + (i === current ? ' active' : '') + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
		}
		html += '<li class="page-item' + (current >= totalPages ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (current + 1) + '">&raquo;</a></li>';
		html += '</ul>';
		$wrap.html(html);
	}

	function getOperatorInviteNowMs() {
		return Date.now() + operatorInviteServerOffsetMs;
	}

	function getOperatorInviteEffectiveStatus(row) {
		if (!row) return 'new';
		if (row.status !== 'new') return row.status;
		var expiresAtTs = parseInt(row.expires_at_ts || 0, 10);
		if (expiresAtTs > 0 && (expiresAtTs * 1000) <= getOperatorInviteNowMs()) {
			return 'expired';
		}
		return 'new';
	}

	function getOperatorInviteEffectiveMeta(row) {
		var status = getOperatorInviteEffectiveStatus(row);
		return {
			status: status,
			label: OPERATOR_INVITE_STATUS_LABELS[status] || row.status_label || status,
			badge: OPERATOR_INVITE_STATUS_BADGES[status] || row.status_badge || 'primary'
		};
	}

	function renderOperatorInviteHistoryActions(row) {
		var effective = getOperatorInviteEffectiveMeta(row);
		var items = [];

		if (row.invite_url && effective.status === 'new') {
			items.push('<li><a class="dropdown-item operator-invite-action-item js-copy-operator-invite" href="#" data-link="' + escHtml(row.invite_url) + '"><i class="pg-icon operator-invite-action-icon">copy</i><span>Скопировать</span></a></li>');
		}
		if (row.qr_url && effective.status === 'new') {
			items.push('<li><a class="dropdown-item operator-invite-action-item js-show-operator-invite-qr" href="#" data-id="' + row.id + '"><i class="pg-icon operator-invite-action-icon">picture</i><span>Показать QR</span></a></li>');
		}
		if (effective.status === 'new') {
			items.push('<li><a class="dropdown-item operator-invite-action-item text-danger js-operator-invite-status" href="#" data-id="' + row.id + '" data-status="revoked"><i class="pg-icon operator-invite-action-icon">close</i><span>Отозвать</span></a></li>');
		}
		if (!items.length) {
			return '<span class="hint-text">—</span>';
		}

		return ''
			+ '<div class="dropdown operator-invite-actions">'
			+ '<button type="button" class="btn btn-default btn-xs dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Действия приглашения">'
			+ '<i class="pg-icon">more_vertical</i>'
			+ '</button>'
			+ '<ul class="dropdown-menu dropdown-menu-end">'
			+ items.join('')
			+ '</ul>'
			+ '</div>';
	}

	function renderOperatorInviteHistoryRows(rows) {
		var $tbody = $('#operator-invite-history-tbody').empty();
		operatorInviteHistoryMap = {};

		if (!rows || !rows.length) {
			$tbody.html('<tr><td colspan="9" class="text-center text-muted p-t-25 p-b-25">Инвайтов пока нет.</td></tr>');
			return;
		}

		rows.forEach(function(row) {
			operatorInviteHistoryMap[String(row.id)] = row;
			var effective = getOperatorInviteEffectiveMeta(row);
			var userName = row.user_name || row.user_login || ('User #' + row.user_id);
			var creator = row.created_by_name || '—';
			var ttl = row.ttl_minutes ? (row.ttl_minutes + ' мин') : '—';
			var actions = renderOperatorInviteHistoryActions(row);
			var activationChatId = row.used_by_chat_id || row.linked_chat_id || row.chat_id || '';
			var linkedUsername = row.linked_telegram_username ? '@' + row.linked_telegram_username : '';
			var linkedProfile = row.linked_telegram_profile_name || '';
			var operatorHtml = '<div class="semi-bold">' + escHtml(userName) + '</div>'
				+ '<div class="hint-text fs-12">' + escHtml(row.user_email || 'ожидает /start') + '</div>';
			if (activationChatId || linkedUsername || linkedProfile) {
					operatorHtml = '<div class="d-flex align-items-center">'
					+ operatorAvatarHtml(row.linked_telegram_avatar_url || '', userName || linkedProfile || linkedUsername || 'OP')
					+ '<div><div class="semi-bold">' + escHtml(userName) + '</div>'
					+ '<div class="hint-text fs-12">' + (activationChatId ? escHtml(activationChatId) : 'chat_id —') + '</div>'
					+ '<div class="hint-text fs-12">' + escHtml($.trim([linkedUsername, linkedProfile].join(' ')) || row.user_email || '—') + '</div></div></div>';
			}

			$tbody.append(
				'<tr data-invite-id="' + escHtml(row.id) + '">'
					+ '<td>#' + escHtml(row.id) + '</td>'
					+ '<td><span class="hint-text fs-12">' + escHtml(creator) + '</span></td>'
					+ '<td>' + operatorHtml + '</td>'
					+ '<td><code>' + escHtml(row.telegram_start_payload || '—') + '</code></td>'
					+ '<td><span class="badge badge-' + escHtml(effective.badge) + '">' + escHtml(effective.label) + '</span></td>'
					+ '<td>' + escHtml(ttl) + '</td>'
					+ '<td><span class="hint-text fs-12">' + escHtml(row.expires_at || '—') + '</span></td>'
					+ '<td><span class="hint-text fs-12">' + escHtml(row.created_at || '—') + '</span></td>'
					+ '<td class="text-center">' + actions + '</td>'
				+ '</tr>'
			);
		});

		$('#operator-invite-history-tbody .operator-invite-actions [data-bs-toggle="dropdown"]').each(function() {
			if (window.bootstrap && bootstrap.Dropdown) {
				bootstrap.Dropdown.getOrCreateInstance(this, {
					popperConfig: function(config) {
						return $.extend(true, config, { strategy: 'fixed' });
					}
				});
			}
		});
	}

	function loadOperatorInviteHistory(page) {
		currentInvitePage = page || 1;
		var payload = collectOperatorInviteHistoryFilters();
		payload.page = currentInvitePage;
		hideInlineAlert($('#operator-invite-page-alert'));

		$.get(AJAX_URL, payload, function(res) {
			if (!res || !res.success) {
				showInlineAlert($('#operator-invite-page-alert'), (res && res.data && res.data.message) || 'Не удалось загрузить историю invite-ссылок.', 'danger');
				return;
			}

			if (res.data.telegram_status) {
				renderOperatorInviteStatus(res.data.telegram_status);
			}
			if (res.data.server_now_ts) {
				operatorInviteServerOffsetMs = (parseInt(res.data.server_now_ts, 10) * 1000) - Date.now();
			}

			renderOperatorInviteHistoryRows(res.data.rows || []);
			renderOperatorInviteHistoryPagination(res.data.total_pages || 1, res.data.page || 1);
			$('#operator-invite-history-stats').text('Найдено: ' + (res.data.total || 0));
		}, 'json').fail(function() {
			showInlineAlert($('#operator-invite-page-alert'), 'Ошибка сервера при загрузке истории invite-ссылок.', 'danger');
		});
	}

	function createOperatorInvite() {
		var userId = parseInt($('#operator-invite-user-id').val(), 10) || 0;
		if (!userId) {
			showInlineAlert($('#operator-invite-alert'), 'Выберите CRM-пользователя.', 'warning');
			return;
		}

		var $btn = $('#btn-create-operator-invite').prop('disabled', true).text('Создаём…');
		hideInlineAlert($('#operator-invite-alert'));

		$.post(AJAX_URL, {
			action: 'me_operator_telegram_invite_create',
			_nonce: NONCE,
			user_id: userId
		}, function(res) {
			if (!res || !res.success) {
				showInlineAlert($('#operator-invite-alert'), (res && res.data && res.data.message) || 'Не удалось создать Telegram-инвайт.', 'danger');
				return;
			}

			if (res.data.telegram_status) {
				renderOperatorInviteStatus(res.data.telegram_status);
			}
			renderOperatorInvitePreview(res.data.invite || {});
			showInlineAlert($('#operator-invite-alert'), res.data.message || 'Telegram-инвайт создан.', 'success');
			loadOperatorUsers(currentOperatorPage);
			loadOperatorInviteHistory(1);
		}, 'json').fail(function() {
			showInlineAlert($('#operator-invite-alert'), 'Ошибка сервера при создании Telegram-инвайта.', 'danger');
		}).always(function() {
			$btn.prop('disabled', !OPERATOR_INVITE_STATUS.operator_ready).html('<i class="pg-icon m-r-5">link</i>Создать инвайт');
		});
	}

	function changeOperatorInviteStatus(id, status) {
		var labels = { revoked: 'отозвать', expired: 'пометить просроченным', used: 'пометить использованным' };
		showConfirm('Подтвердите: ' + (labels[status] || 'изменить статус') + ' инвайта?', function() {
			$.post(AJAX_URL, {
				action: 'me_operator_telegram_invite_status',
				_nonce: NONCE,
				invite_id: id,
				status: status
			}, function(res) {
				if (!res || !res.success) {
					showToast((res && res.data && res.data.message) || 'Не удалось обновить статус инвайта.', 'danger');
					return;
				}
				showToast(res.data.message || 'Статус инвайта обновлён.', 'success');
				loadOperatorUsers(currentOperatorPage);
				loadOperatorInviteHistory(currentInvitePage);
			}, 'json').fail(function() {
				showToast('Ошибка сервера при обновлении статуса инвайта.', 'danger');
			});
		}, {
			btnClass: status === 'revoked' ? 'btn-danger' : 'btn-warning',
			btnText: labels[status] ? labels[status].charAt(0).toUpperCase() + labels[status].slice(1) : 'Подтвердить'
		});
	}

	function refreshOperatorProfile(userId) {
		userId = parseInt(userId, 10) || 0;
		if (!userId) return;

		$.post(AJAX_URL, {
			action: 'me_operator_telegram_profile_refresh',
			_nonce: NONCE,
			user_id: userId
		}, function(res) {
			if (!res || !res.success) {
				showToast((res && res.data && res.data.message) || 'Не удалось обновить Telegram-профиль.', 'danger');
				return;
			}
			showToast(res.data.message || 'Telegram-профиль обновлён.', 'success');
			loadOperatorUsers(currentOperatorPage);
			loadOperatorInviteHistory(currentInvitePage);
		}, 'json').fail(function() {
			showToast('Ошибка сервера при обновлении Telegram-профиля.', 'danger');
		});
	}

	function refreshOperatorInviteHistoryUiState() {
		renderOperatorInviteHistoryRows(Object.keys(operatorInviteHistoryMap).map(function(key) {
			return operatorInviteHistoryMap[key];
		}));
	}

	$(document).on('click', '.js-open-operator-invite-modal', function() {
		if ($(this).hasClass('disabled') || $(this).prop('disabled')) {
			return false;
		}
		openOperatorInviteModal(parseInt($(this).data('user-id'), 10) || 0, $(this).data('user-name'));
	});

	$(document).on('click', '.js-copy-operator-chat-id', function(e) {
		e.preventDefault();
		copyText($(this).data('chat-id'), 'chat_id оператора скопирован.');
	});

	$(document).on('click', '.js-refresh-operator-profile', function(e) {
		e.preventDefault();
		refreshOperatorProfile($(this).data('user-id'));
	});

	$('#btn-operator-users-search').on('click', function() {
		loadOperatorUsers(1);
	});

	$('#btn-operator-users-reset').on('click', function() {
		$('#of-search').val('');
		$('#of-status').val('').trigger('change.select2');
		$('#of-date-from').val('');
		$('#of-date-to').val('');
		$('#of-per-page').val('25').trigger('change.select2');
		loadOperatorUsers(1);
	});

	$('#of-search').on('keydown', function(e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			loadOperatorUsers(1);
		}
	});

	$('#operator-users-pagination').on('click', '.page-link', function(e) {
		e.preventDefault();
		var page = parseInt($(this).data('page'), 10);
		if (page > 0) {
			loadOperatorUsers(page);
		}
	});

	$(document).on('click', '.js-focus-operator-invite-history', function(e) {
		e.preventDefault();
		focusOperatorInviteHistoryOnUser(parseInt($(this).data('user-id'), 10), $(this).data('user-name'));
	});

	$('#btn-create-operator-invite').on('click', function() {
		createOperatorInvite();
	});

	$('#btn-copy-operator-invite-link').on('click', function() {
		copyText($('#operator-invite-link-preview').val(), 'Ссылка Telegram-инвайта скопирована.');
	});

	$(document).on('click', '.js-copy-operator-invite', function(e) {
		e.preventDefault();
		copyText($(this).data('link'), 'Ссылка приглашения скопирована.');
	});

	$(document).on('click', '.js-show-operator-invite-qr', function(e) {
		e.preventDefault();
		var inviteId = parseInt($(this).data('id'), 10);
		if (!inviteId || !operatorInviteHistoryMap[String(inviteId)]) {
			return;
		}
		if (!$('#operator-telegram-invite-modal').hasClass('show')) {
			openOperatorInviteModal(operatorInviteHistoryMap[String(inviteId)].user_id, operatorInviteHistoryMap[String(inviteId)].user_name);
		}
		renderOperatorInvitePreview(operatorInviteHistoryMap[String(inviteId)]);
	});

	$(document).on('click', '.js-operator-invite-status', function(e) {
		e.preventDefault();
		changeOperatorInviteStatus(parseInt($(this).data('id'), 10), String($(this).data('status') || ''));
	});

	$('#btn-operator-invite-history-search').on('click', function() {
		loadOperatorInviteHistory(1);
	});

	$('#btn-operator-invite-history-reset').on('click', function() {
		$('#operator-invite-history-search').val('');
		$('#operator-invite-history-status').val('').trigger('change.select2');
		$('#operator-invite-history-date-from').val('');
		$('#operator-invite-history-date-to').val('');
		$('#operator-invite-history-per-page').val('25').trigger('change.select2');
		clearOperatorInviteHistoryFocus();
		loadOperatorInviteHistory(1);
	});

	$('#btn-operator-invite-history-clear-user').on('click', function() {
		clearOperatorInviteHistoryFocus();
		loadOperatorInviteHistory(1);
	});

	$('#operator-invite-history-pagination').on('click', '.page-link', function(e) {
		e.preventDefault();
		var page = parseInt($(this).data('page'), 10);
		if (page > 0) {
			loadOperatorInviteHistory(page);
		}
	});

	$('#of-status, #of-per-page, #operator-invite-history-status, #operator-invite-history-per-page').each(function() {
		var $el = $(this);
		if ($el.length && !$el.hasClass('select2-hidden-accessible')) {
			$el.select2();
		}
	});

	document.addEventListener('visibilitychange', function() {
		if (!document.hidden) {
			refreshOperatorInviteHistoryUiState();
		}
	});

	renderOperatorInviteHistoryFocus();
	renderOperatorInviteStatus(OPERATOR_INVITE_STATUS);
	loadOperatorUsers(1);
	setInterval(refreshOperatorInviteHistoryUiState, 15000);
	if (CAN_INVITE) {
		loadOperatorInviteHistory(1);
	}
}(jQuery));
</script>
		<?php
	},
	99
);
?>

<?php get_footer(); ?>

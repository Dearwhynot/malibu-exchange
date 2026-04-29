<?php
/*
Template Name: Merchants Page
Slug: merchants
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

$current_uid = get_current_user_id();
$is_root = crm_is_root( $current_uid );
if ( $is_root ) {
	malibu_exchange_render_root_company_scope_denied();
}

if ( ! crm_can_manage_merchants() ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$current_company_id  = crm_require_company_page_context();
$telegram_invite_status = crm_telegram_get_configuration_status( $current_company_id );
$companies           = array_values( array_filter( crm_get_all_companies_list(), static fn( $item ) => (int) $item->id === $current_company_id ) );
$company_ids         = array_map( static fn( $company ) => (int) $company->id, $companies );
$referrers_by_company = crm_get_merchants_by_company_ids( $company_ids );

$companies_payload = array_map(
	static function ( $company ) {
		return [
			'id'   => (int) $company->id,
			'code' => (string) $company->code,
			'name' => (string) $company->name,
		];
	},
	$companies
);

$vendor_img_uri = get_template_directory_uri() . '/vendor/pages/assets/img';
$nonce_list     = wp_create_nonce( 'me_merchants_list' );
$nonce_save     = wp_create_nonce( 'me_merchants_save' );
$nonce_status   = wp_create_nonce( 'me_merchants_status' );
$nonce_invite   = wp_create_nonce( 'me_merchants_invite' );
$nonce_ledger   = wp_create_nonce( 'me_merchants_ledger' );
$nonce_orders   = wp_create_nonce( 'me_merchants_orders' );

$can_create = crm_can_access( 'merchants.create' );
$can_edit   = crm_can_access( 'merchants.edit' );
$can_block  = crm_can_access( 'merchants.block' );
$can_invite = crm_can_access( 'merchants.invite' );
$can_ledger = crm_can_access( 'merchants.ledger' );
$can_orders = crm_can_access( 'orders.view' );

get_header();
?>

<?php get_template_part( 'template-parts/sidebar' ); ?>

<div class="page-container">

	<div class="header">
		<a href="#" class="btn-link toggle-sidebar d-lg-none pg-icon btn-icon-link" data-toggle="sidebar">menu</a>
		<div class="">
			<div class="brand inline">
				<img src="<?php echo esc_url( $vendor_img_uri . '/logo.png' ); ?>" alt="logo"
				     data-src="<?php echo esc_url( $vendor_img_uri . '/logo.png' ); ?>"
				     data-src-retina="<?php echo esc_url( $vendor_img_uri . '/logo_2x.png' ); ?>"
				     width="78" height="22">
			</div>
		</div>
		<div class="d-flex align-items-center">
			<div class="dropdown pull-right d-lg-block d-none">
				<button class="profile-dropdown-toggle" type="button" data-bs-toggle="dropdown"
				        aria-haspopup="true" aria-expanded="false" aria-label="profile dropdown">
					<span class="thumbnail-wrapper d32 circular inline">
						<img src="<?php echo esc_url( $vendor_img_uri . '/profiles/avatar.jpg' ); ?>"
						     alt="" width="32" height="32">
					</span>
				</button>
				<div class="dropdown-menu dropdown-menu-right profile-dropdown" role="menu">
					<a href="#" class="dropdown-item">
						<span>Вход как<br><b><?php echo esc_html( wp_get_current_user()->display_name ); ?></b></span>
					</a>
					<div class="dropdown-divider"></div>
					<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="dropdown-item">Выйти</a>
				</div>
			</div>
			<a href="#" class="header-icon m-l-5 sm-no-margin d-inline-block"
			   data-toggle="quickview" data-toggle-element="#quickview">
				<i class="pg-icon btn-icon-link">menu_add</i>
			</a>
		</div>
	</div>

	<div class="page-content-wrapper">
		<div class="content">

			<div class="jumbotron" data-pages="parallax">
				<div class="container-fluid container-fixed-lg sm-p-l-0 sm-p-r-0">
					<div class="inner">
						<ol class="breadcrumb">
							<li class="breadcrumb-item"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a></li>
							<li class="breadcrumb-item active">Мерчанты</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">

				<div class="m-b-15">
					<h3 class="m-b-5">Мерчанты</h3>
					<p class="hint-text m-b-0">
						Company-scoped контур клиентов компании с инвайтами, ledger и привязкой к платёжным ордерам.
					</p>
				</div>

				<div class="card card-default m-b-20">
					<div class="card-body p-t-20 p-b-15">
						<div class="row g-2 align-items-center m-b-10">
							<div class="col-12 col-md-4 col-lg-3">
								<div class="input-group">
									<span class="input-group-text"><i class="pg-icon">search</i></span>
									<input type="search" id="mf-search" class="form-control"
									       placeholder="chat_id, username, имя">
								</div>
							</div>
							<?php if ( $is_root ) : ?>
							<div class="col-6 col-md-2">
								<select id="mf-company" class="full-width" data-init-plugin="select2">
									<option value="">Все компании</option>
									<?php foreach ( $companies_payload as $company ) : ?>
									<option value="<?php echo (int) $company['id']; ?>">
										<?php echo esc_html( $company['name'] ); ?>
									</option>
									<?php endforeach; ?>
								</select>
							</div>
							<?php endif; ?>
							<div class="col-6 col-md-2">
								<select id="mf-status" class="full-width" data-init-plugin="select2">
									<option value="">Все статусы</option>
									<?php foreach ( crm_merchant_statuses() as $status_code => $label ) : ?>
									<option value="<?php echo esc_attr( $status_code ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-6 col-md-2">
								<input type="date" id="mf-date-from" class="form-control" placeholder="Дата с">
							</div>
							<div class="col-6 col-md-2">
								<input type="date" id="mf-date-to" class="form-control" placeholder="Дата по">
							</div>
						</div>

						<div class="row g-2 align-items-center">
							<div class="col-4 col-md-1">
								<select id="mf-per-page" class="full-width" data-init-plugin="select2">
									<option value="25">25</option>
									<option value="50">50</option>
									<option value="100">100</option>
								</select>
							</div>
							<div class="col-8 col-md-3 d-flex gap-2">
								<button type="button" id="btn-merchants-search" class="btn btn-primary">
									<i class="pg-icon">search</i> Найти
								</button>
								<button type="button" id="btn-merchants-reset" class="btn btn-default">
									Сброс
								</button>
							</div>
						</div>
					</div>
				</div>

				<div class="d-flex justify-content-between align-items-center m-b-10">
					<div id="merchants-stats" class="text-muted small"></div>
					<div class="d-flex align-items-center gap-2">
						<div id="merchants-loading" class="text-muted small d-none">
							<span class="pg-icon" style="animation:spin 1s linear infinite;display:inline-block;">refresh</span>
							Загрузка…
						</div>
						<?php if ( $can_invite && ! $is_root ) : ?>
						<button type="button" id="btn-open-telegram-invite-modal" class="btn btn-primary">
							<i class="pg-icon m-r-5">link</i>Создать инвайт
						</button>
						<?php endif; ?>
						<?php if ( $can_create ) : ?>
						<button type="button" id="btn-open-merchant-modal" class="btn btn-default">
							<i class="pg-icon m-r-5">add</i>Создать мерчанта
						</button>
						<?php endif; ?>
					</div>
				</div>

				<div class="card card-default">
					<div class="card-body p-0">
						<div class="table-responsive">
							<table class="table table-hover m-b-0" id="merchants-table">
								<thead>
									<tr>
										<th style="width:60px">#</th>
										<th style="width:220px">Мерчант</th>
										<th style="width:150px">chat_id</th>
										<th style="width:170px">Telegram</th>
										<?php if ( $is_root ) : ?>
										<th style="width:180px">Компания</th>
										<?php endif; ?>
										<th style="width:110px">Статус</th>
										<th style="width:120px">Наценка</th>
										<th style="width:130px">Бонус</th>
										<th style="width:130px">Рефка</th>
										<th style="width:150px">Создан</th>
										<th style="width:200px" class="text-right">Действия</th>
									</tr>
								</thead>
								<tbody id="merchants-tbody">
									<tr>
										<td colspan="<?php echo $is_root ? 11 : 10; ?>" class="text-center p-t-30 p-b-30 text-muted">
											Нажмите «Найти» для загрузки данных.
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<div id="merchants-pagination" class="d-flex justify-content-between align-items-center m-t-15 m-b-30"></div>

				<div class="card card-default m-b-30" id="telegram-invite-history-card">
					<div class="card-header d-flex justify-content-between align-items-center">
						<div class="card-title">История Telegram-инвайтов</div>
						<div class="d-flex align-items-center gap-2">
							<div id="telegram-invite-history-focus-meta" class="hint-text fs-12">Все invite-ссылки компании</div>
							<button type="button" id="btn-telegram-invite-history-clear-merchant" class="btn btn-default btn-xs d-none">Снять фокус</button>
						</div>
					</div>
					<div class="card-body">
						<div id="telegram-invite-page-alert" class="alert d-none m-b-15" role="alert"></div>
						<div id="telegram-invite-page-status-host" class="m-b-15"></div>
						<div class="row g-2 align-items-center m-b-15">
							<div class="col-md-4">
								<input type="search" id="tg-invite-history-search" class="form-control" placeholder="payload, chat_id, username, имя">
							</div>
							<div class="col-md-2">
								<select id="tg-invite-history-status" class="full-width" data-init-plugin="select2">
									<option value="">Все статусы</option>
									<?php foreach ( crm_merchant_invite_statuses() as $status_code => $label ) : ?>
									<option value="<?php echo esc_attr( $status_code ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-2">
								<input type="date" id="tg-invite-history-date-from" class="form-control">
							</div>
							<div class="col-md-2">
								<input type="date" id="tg-invite-history-date-to" class="form-control">
							</div>
							<div class="col-md-2">
								<select id="tg-invite-history-per-page" class="full-width" data-init-plugin="select2">
									<option value="25">25</option>
									<option value="50">50</option>
									<option value="100">100</option>
								</select>
							</div>
						</div>
						<div class="d-flex justify-content-between align-items-center m-b-15">
							<div id="telegram-invite-history-stats" class="text-muted small">Нажмите «Найти», чтобы загрузить историю invite-ссылок.</div>
							<div class="d-flex align-items-center gap-2">
								<button type="button" id="btn-telegram-invite-history-reset" class="btn btn-default btn-sm">
									Сброс
								</button>
								<button type="button" id="btn-telegram-invite-history-search" class="btn btn-primary btn-sm">
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
										<th>Мерчант / chat_id</th>
										<th style="width:150px">Payload</th>
										<th style="width:110px">Статус</th>
										<th style="width:120px">TTL</th>
										<th style="width:160px">Активен до</th>
										<th style="width:160px">Создан</th>
										<th style="width:190px" class="text-center">Действия</th>
									</tr>
								</thead>
								<tbody id="telegram-invite-history-tbody">
									<tr>
										<td colspan="9" class="text-center text-muted p-t-25 p-b-25">Нажмите «Найти», чтобы загрузить историю invite-ссылок.</td>
									</tr>
								</tbody>
							</table>
						</div>
						<div class="d-flex justify-content-end align-items-center m-t-10">
							<div id="telegram-invite-history-pagination"></div>
						</div>
					</div>
				</div>

			</div>
		</div>

		<div class="container-fluid container-fixed-lg footer">
			<div class="copyright sm-text-center">
				<p class="small-text no-margin pull-left sm-pull-reset">
					©2014-2020 All Rights Reserved. Pages® and/or its subsidiaries or affiliates are registered trademark of Revox Ltd.
				</p>
				<div class="clearfix"></div>
			</div>
		</div>
	</div>
</div>

<?php get_template_part( 'template-parts/toast-host' ); ?>

<div class="modal fade" id="merchant-telegram-invite-modal" tabindex="-1" role="dialog" aria-labelledby="merchant-telegram-invite-title" aria-hidden="true">
	<div class="modal-dialog modal-lg" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title" id="merchant-telegram-invite-title">Telegram-инвайты мерчантов</h4>
				<button type="button" class="close" data-bs-dismiss="modal" aria-label="Закрыть">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="modal-body">
				<div id="telegram-invite-alert" class="alert d-none m-b-15" role="alert"></div>
				<div id="telegram-invite-modal-status-host" class="m-b-15"></div>

				<div class="card card-default m-b-20">
					<div class="card-header">
						<div class="card-title">Создать Telegram-инвайт</div>
					</div>
					<div class="card-body">
						<form id="telegram-invite-create-form">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group">
										<label for="tg-invite-markup-type">Тип наценки</label>
										<select id="tg-invite-markup-type" class="full-width" data-init-plugin="select2">
											<?php foreach ( crm_merchant_markup_types() as $type_code => $label ) : ?>
											<option value="<?php echo esc_attr( $type_code ); ?>"><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group">
										<label for="tg-invite-markup-value">Значение наценки</label>
										<input type="number" class="form-control" id="tg-invite-markup-value" value="0" step="0.00000001" min="0">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-8">
									<div class="form-group">
										<label for="tg-invite-note">Заметка к будущему мерчанту</label>
										<textarea id="tg-invite-note" class="form-control" rows="2" placeholder="Например: трафик с группы Phuket / VIP поток"></textarea>
									</div>
								</div>
								<div class="col-md-4 d-flex align-items-end">
									<button type="button" id="btn-create-telegram-invite" class="btn btn-primary btn-block">
										<i class="pg-icon m-r-5">link</i>Создать инвайт
									</button>
								</div>
							</div>
						</form>
					</div>
				</div>

				<div id="telegram-invite-preview" class="card card-default m-b-20 d-none">
					<div class="card-header">
						<div class="card-title">Последний созданный инвайт</div>
					</div>
					<div class="card-body">
						<div class="row">
							<div class="col-md-7">
								<div class="form-group">
									<label for="tg-invite-link-preview">Ссылка</label>
									<div class="input-group">
										<input type="text" id="tg-invite-link-preview" class="form-control" readonly>
										<span class="input-group-btn">
											<button type="button" id="btn-copy-telegram-invite-link" class="btn btn-default">Копировать</button>
										</span>
									</div>
									<p class="hint-text m-t-5" id="tg-invite-preview-meta">—</p>
								</div>
							</div>
							<div class="col-md-5">
								<div class="text-center p-15" style="background:#f7fafc;border-radius:12px;border:1px solid #e8edf2">
									<div class="fs-12 semi-bold m-b-10 text-uppercase" style="letter-spacing:.08em;color:#3b5998">Telegram Invite QR</div>
									<div id="tg-invite-qr-preview-wrap" class="m-b-10"></div>
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

<div class="modal fade" id="merchant-modal" tabindex="-1" role="dialog" aria-labelledby="merchant-modal-title" aria-hidden="true">
	<div class="modal-dialog modal-lg" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title" id="merchant-modal-title">Мерчант</h4>
				<button type="button" class="close" data-bs-dismiss="modal" aria-label="Закрыть">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="modal-body">
				<div id="merchant-form-alert" class="alert d-none m-b-15" role="alert"></div>
				<form id="merchant-form">
					<input type="hidden" id="merchant-id" name="merchant_id" value="0">

					<?php if ( $is_root ) : ?>
					<div class="row">
						<div class="col-md-12">
							<div class="form-group">
								<label for="merchant-company-id">Компания</label>
								<select id="merchant-company-id" name="company_id" class="full-width" data-init-plugin="select2">
									<option value="">Выберите компанию</option>
									<?php foreach ( $companies_payload as $company ) : ?>
									<option value="<?php echo (int) $company['id']; ?>"><?php echo esc_html( $company['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="hint-text fs-12 m-t-5" id="merchant-company-hint">Компания фиксируется при создании.</p>
							</div>
						</div>
					</div>
					<?php else : ?>
					<input type="hidden" id="merchant-company-id" name="company_id" value="<?php echo (int) $current_company_id; ?>">
					<?php endif; ?>

					<div class="row">
						<div class="col-md-6">
							<div class="form-group">
								<label for="merchant-chat-id">chat_id</label>
								<input type="text" class="form-control" id="merchant-chat-id" name="chat_id" placeholder="123456789">
							</div>
						</div>
						<div class="col-md-6">
							<div class="form-group">
								<label for="merchant-telegram-username">Telegram username</label>
								<input type="text" class="form-control" id="merchant-telegram-username" name="telegram_username" placeholder="username">
							</div>
						</div>
					</div>

					<div class="row m-b-10">
						<div class="col-md-3">
							<div id="merchant-avatar-preview" class="text-center p-10" style="background:#f7fafc;border-radius:10px;border:1px solid #e8edf2">
								<div class="hint-text fs-11 text-uppercase m-b-5">Telegram avatar</div>
								<div class="thumbnail-wrapper circular inline bg-complete text-white d-flex align-items-center justify-content-center" style="width:72px;height:72px;margin:0 auto;overflow:hidden;">
									<span id="merchant-avatar-fallback">TG</span>
									<img id="merchant-avatar-image" src="" data-src="" data-src-retina="" alt="Telegram avatar" width="72" height="72" style="display:none;width:72px;height:72px;object-fit:cover;">
								</div>
							</div>
						</div>
						<div class="col-md-3">
							<div class="form-group">
								<label for="merchant-telegram-first-name">Telegram first name</label>
								<input type="text" class="form-control" id="merchant-telegram-first-name" name="telegram_first_name" placeholder="Alex">
							</div>
						</div>
						<div class="col-md-3">
							<div class="form-group">
								<label for="merchant-telegram-last-name">Telegram last name</label>
								<input type="text" class="form-control" id="merchant-telegram-last-name" name="telegram_last_name" placeholder="Ivanov">
							</div>
						</div>
						<div class="col-md-3">
							<div class="form-group">
								<label for="merchant-telegram-language-code">Language code</label>
								<input type="text" class="form-control" id="merchant-telegram-language-code" name="telegram_language_code" placeholder="ru" maxlength="16">
							</div>
						</div>
					</div>

					<div class="row">
						<div class="col-md-6">
							<div class="form-group">
								<label for="merchant-name">Имя / псевдоним</label>
								<input type="text" class="form-control" id="merchant-name" name="name" placeholder="Например, Surf Pay">
							</div>
						</div>
						<div class="col-md-6">
							<div class="form-group">
								<label for="merchant-status">Статус</label>
								<select id="merchant-status" name="status" class="full-width" data-init-plugin="select2">
									<?php foreach ( crm_merchant_statuses() as $status_code => $label ) : ?>
									<option value="<?php echo esc_attr( $status_code ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
					</div>

					<div class="row">
						<div class="col-md-4">
							<div class="form-group">
								<label for="merchant-markup-type">Тип наценки</label>
								<select id="merchant-markup-type" name="base_markup_type" class="full-width" data-init-plugin="select2">
									<?php foreach ( crm_merchant_markup_types() as $type_code => $label ) : ?>
									<option value="<?php echo esc_attr( $type_code ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div class="col-md-4">
							<div class="form-group">
								<label for="merchant-markup-value">Значение наценки</label>
								<input type="text" class="form-control" id="merchant-markup-value" name="base_markup_value" value="0">
							</div>
						</div>
						<div class="col-md-4">
							<div class="form-group">
								<label for="merchant-ref-code">ref_code</label>
								<input type="text" class="form-control" id="merchant-ref-code" name="ref_code" placeholder="SURF-REF">
							</div>
						</div>
					</div>

					<div class="row">
						<div class="col-md-6">
							<div class="form-group">
								<label for="merchant-referred-by">Кто его реферал-пригласитель</label>
								<select id="merchant-referred-by" name="referred_by_merchant_id" class="full-width" data-init-plugin="select2">
									<option value="">Без реферера</option>
								</select>
							</div>
						</div>
						<div class="col-md-6">
							<div class="form-group">
								<label for="merchant-note">Заметка</label>
								<textarea id="merchant-note" name="note" class="form-control" rows="3" placeholder="Внутренняя заметка"></textarea>
							</div>
						</div>
					</div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-bs-dismiss="modal">Отмена</button>
				<button type="button" id="btn-save-merchant" class="btn btn-primary">
					<span class="btn-label">Сохранить</span>
				</button>
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="merchant-ledger-modal" tabindex="-1" role="dialog" aria-labelledby="merchant-ledger-title" aria-hidden="true">
	<div class="modal-dialog modal-xl" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title" id="merchant-ledger-title">Ledger мерчанта</h4>
				<button type="button" class="close" data-bs-dismiss="modal" aria-label="Закрыть">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="modal-body">
				<div class="row m-b-15">
					<div class="col-md-4">
						<div class="card card-default p-15">
							<div class="small hint-text">Бонусный баланс</div>
							<div class="fs-16 bold" id="ledger-bonus-balance">0 USDT</div>
						</div>
					</div>
					<div class="col-md-4">
						<div class="card card-default p-15">
							<div class="small hint-text">Реферальный баланс</div>
							<div class="fs-16 bold" id="ledger-referral-balance">0 USDT</div>
						</div>
					</div>
					<div class="col-md-4">
						<div class="card card-default p-15">
							<div class="small hint-text">Итого</div>
							<div class="fs-16 bold" id="ledger-total-balance">0 USDT</div>
						</div>
					</div>
				</div>
				<div class="table-responsive">
					<table class="table table-hover m-b-0">
						<thead>
							<tr>
								<th style="width:80px">#</th>
								<th style="width:180px">Тип</th>
								<th style="width:120px">Сумма</th>
								<th>Источник</th>
								<th>Комментарий</th>
								<th style="width:160px">Дата</th>
							</tr>
						</thead>
						<tbody id="merchant-ledger-tbody">
							<tr>
								<td colspan="6" class="text-center text-muted p-t-25 p-b-25">Нет данных.</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="merchant-orders-modal" tabindex="-1" role="dialog" aria-labelledby="merchant-orders-title" aria-hidden="true">
	<div class="modal-dialog modal-xl" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title" id="merchant-orders-title">Ордера мерчанта</h4>
				<button type="button" class="close" data-bs-dismiss="modal" aria-label="Закрыть">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="modal-body">
				<div class="table-responsive">
					<table class="table table-hover m-b-0">
						<thead>
							<tr>
								<th style="width:80px">#</th>
								<th style="width:160px">Дата</th>
								<th style="width:100px">Провайдер</th>
								<th style="width:190px">Merchant ID</th>
								<th style="width:100px">Статус</th>
								<th style="width:120px">USDT</th>
								<th style="width:120px">RUB</th>
								<th style="width:120px">Наценка</th>
								<th style="width:120px">Наша fee</th>
								<th style="width:120px">Профит</th>
								<th style="width:120px">Рефка</th>
							</tr>
						</thead>
						<tbody id="merchant-orders-tbody">
							<tr>
								<td colspan="11" class="text-center text-muted p-t-25 p-b-25">Нет данных.</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>

<?php
add_action(
	'wp_footer',
	function () use ( $nonce_list, $nonce_save, $nonce_status, $nonce_invite, $nonce_ledger, $nonce_orders, $is_root, $can_create, $can_edit, $can_block, $can_invite, $can_ledger, $can_orders, $companies_payload, $referrers_by_company, $current_company_id, $telegram_invite_status ) {
?>
<style>
.telegram-invite-actions .dropdown-item.telegram-invite-action-item {
	display: flex;
	align-items: center;
	gap: 12px;
}
.telegram-invite-actions .telegram-invite-action-icon {
	flex: 0 0 24px;
	width: 24px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	line-height: 1;
}
</style>
<script>
(function ($) {
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCES = {
		list:   '<?php echo esc_js( $nonce_list ); ?>',
		save:   '<?php echo esc_js( $nonce_save ); ?>',
		status: '<?php echo esc_js( $nonce_status ); ?>',
		invite: '<?php echo esc_js( $nonce_invite ); ?>',
		ledger: '<?php echo esc_js( $nonce_ledger ); ?>',
		orders: '<?php echo esc_js( $nonce_orders ); ?>'
	};
	var IS_ROOT    = <?php echo $is_root ? 'true' : 'false'; ?>;
	var CAN_CREATE = <?php echo $can_create ? 'true' : 'false'; ?>;
	var CAN_EDIT   = <?php echo $can_edit ? 'true' : 'false'; ?>;
	var CAN_BLOCK  = <?php echo $can_block ? 'true' : 'false'; ?>;
	var CAN_INVITE = <?php echo $can_invite ? 'true' : 'false'; ?>;
	var CAN_LEDGER = <?php echo $can_ledger ? 'true' : 'false'; ?>;
	var CAN_ORDERS = <?php echo $can_orders ? 'true' : 'false'; ?>;
	var TELEGRAM_INVITE_STATUS = <?php echo crm_json_for_inline_js( $telegram_invite_status ); ?> || {};
	var TELEGRAM_INVITE_STATUS_LABELS = <?php echo crm_json_for_inline_js( crm_merchant_invite_statuses() ); ?> || {};
	var TELEGRAM_INVITE_STATUS_BADGES = { new: 'primary', used: 'success', expired: 'warning', revoked: 'secondary' };

	var REFERRERS_BY_COMPANY = <?php echo crm_json_for_inline_js( $referrers_by_company ); ?> || {};

	var currentPage = 1;
	var currentTelegramInvitePage = 1;
	var currentTelegramInviteRows = [];
	var currentTelegramInviteMerchantId = 0;
	var currentTelegramInviteMerchantName = '';
	var currentLedgerMerchant = null;
	var currentOrdersMerchant = null;
	var latestTelegramInvite = null;
	var telegramInviteHistoryMap = {};
	var telegramInviteServerOffsetMs = 0;

	function escHtml(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	function showToast(message, type) {
		if (window.MalibuToast && typeof window.MalibuToast.show === 'function') {
			window.MalibuToast.show(message, type || 'info');
			return;
		}
		alert(message);
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

	function merchantAvatarInitials(label) {
		return (label || 'TG').replace(/\s+/g, ' ').trim().substring(0, 2).toUpperCase() || 'TG';
	}

	function merchantAvatarImage(url, size, alt) {
		var safeUrl = escHtml(url);
		var safeSize = parseInt(size, 10) || 40;
		var safeAlt = escHtml(alt || 'Profile Image');
		return '<img src="' + safeUrl + '" data-src="' + safeUrl + '" data-src-retina="' + safeUrl + '" alt="' + safeAlt + '" width="' + safeSize + '" height="' + safeSize + '" style="width:' + safeSize + 'px;height:' + safeSize + 'px;object-fit:cover;">';
	}

	function merchantAvatarHtml(url, label) {
		if (url) {
			return '<span class="thumbnail-wrapper d40 circular inline m-r-10" style="overflow:hidden;">' + merchantAvatarImage(url, 40, label || 'Profile Image') + '</span>';
		}
		return '<span class="thumbnail-wrapper d40 circular inline m-r-10 bg-complete text-white" style="display:inline-flex;align-items:center;justify-content:center;font-weight:700;">' + escHtml(merchantAvatarInitials(label)) + '</span>';
	}

	function setMerchantAvatarPreview(url, label) {
		if (url) {
			$('#merchant-avatar-image')
				.attr('src', url)
				.attr('data-src', url)
				.attr('data-src-retina', url)
				.show();
			$('#merchant-avatar-fallback').hide();
			return;
		}
		$('#merchant-avatar-image')
			.attr('src', '')
			.attr('data-src', '')
			.attr('data-src-retina', '')
			.hide();
		$('#merchant-avatar-fallback').text(merchantAvatarInitials(label)).show();
	}

	function telegramInviteStatusHtml(status) {
		var missingLabels = $.map(status.missing_fields || [], function (item) {
			return item && item.label ? item.label : null;
		});

		if (status.invite_ready) {
			return '<div class="alert alert-success bordered m-b-0"><strong>Telegram-инвайты готовы к работе.</strong><br>'
				+ (status.bot_handle ? 'Бот: ' + escHtml(status.bot_handle) + '. ' : '')
				+ 'Можно создавать deep-link, показывать QR и ждать запуск /start от мерчанта.</div>';
		}

		if (status.is_configured) {
			var html = '<div class="alert alert-warning bordered m-b-0"><strong>Инвайт в Telegram пока недоступен.</strong><br>'
				+ escHtml(status.blocked_reason || 'Сначала подключите callback для этой компании.');
			if (status.webhook_last_error) {
				html += '<div class="m-t-10"><strong>Последняя ошибка Telegram API:</strong> ' + escHtml(status.webhook_last_error) + '</div>';
			}
			html += '<div class="m-t-10">Откройте «Настройки», проверьте имя бота и токен, затем подключите callback.</div></div>';
			return html;
		}

		var danger = '<div class="alert alert-danger bordered m-b-0"><strong>Инвайт в Telegram недоступен.</strong><br>'
			+ 'Для создания ссылки заполните настройки Telegram-бота: имя бота и токен. Перейдите в «Настройки».';
		if (missingLabels.length) {
			danger += '<div class="m-t-10"><strong>Не заполнено:</strong> ' + escHtml(missingLabels.join(', ')) + '.</div>';
		}
		danger += '</div>';
		return danger;
	}

	function renderTelegramInviteStatus(status) {
		TELEGRAM_INVITE_STATUS = status || {};
		var html = telegramInviteStatusHtml(TELEGRAM_INVITE_STATUS);
		$('#telegram-invite-page-status-host, #telegram-invite-modal-status-host').html(html);
		$('#btn-create-telegram-invite').prop('disabled', !TELEGRAM_INVITE_STATUS.invite_ready);
	}

	function renderReferrerSelect(companyId, selectedReferrerId, currentMerchantId) {
		var html = '<option value="">Без реферера</option>';
		var referrers = REFERRERS_BY_COMPANY[String(companyId)] || [];
		referrers.forEach(function (merchant) {
			if (currentMerchantId && parseInt(merchant.id, 10) === parseInt(currentMerchantId, 10)) {
				return;
			}
			var label = merchant.name || ('Merchant #' + merchant.id);
			label += merchant.chat_id ? ' [' + merchant.chat_id + ']' : '';
			html += '<option value="' + escHtml(merchant.id) + '">' + escHtml(label) + '</option>';
		});
		$('#merchant-referred-by').html(html);
		$('#merchant-referred-by').val(selectedReferrerId ? String(selectedReferrerId) : '');
		if ($('#merchant-referred-by').hasClass('select2-hidden-accessible')) {
			$('#merchant-referred-by').trigger('change.select2');
		}
	}

	function syncMerchantFormOptions(companyId, selectedReferrerId, currentMerchantId) {
		if (!companyId) {
			renderReferrerSelect('', '', currentMerchantId);
			return;
		}
		renderReferrerSelect(companyId, selectedReferrerId, currentMerchantId);
	}

	function setMerchantFormMode(isEdit) {
		$('#merchant-modal-title').text(isEdit ? 'Редактировать мерчанта' : 'Создать мерчанта');
		if (IS_ROOT) {
			$('#merchant-company-id').prop('disabled', isEdit);
			$('#merchant-company-hint').text(isEdit ? 'Компания не меняется после создания.' : 'Выберите компанию для мерчанта.');
		}
	}

	function resetMerchantForm() {
		$('#merchant-form')[0].reset();
		hideInlineAlert($('#merchant-form-alert'));
		$('#merchant-id').val('0');
		$('#merchant-status').val('active').trigger('change.select2');
		$('#merchant-markup-type').val('percent').trigger('change.select2');
		setMerchantAvatarPreview('', 'TG');
		var companyId = IS_ROOT ? ($('#merchant-company-id').val() || '') : '<?php echo (int) $current_company_id; ?>';
		syncMerchantFormOptions(companyId, '', 0);
	}

	function collectMerchantFilters() {
		return {
			action:    'me_merchants_list',
			_nonce:    NONCES.list,
			page:      currentPage,
			per_page:  $('#mf-per-page').val(),
			search:    $('#mf-search').val(),
			status:    $('#mf-status').val(),
			date_from: $('#mf-date-from').val(),
			date_to:   $('#mf-date-to').val(),
			company_id: IS_ROOT ? ($('#mf-company').val() || '') : '<?php echo (int) $current_company_id; ?>'
		};
	}

	function loadMerchants(page) {
		currentPage = page || 1;
		var payload = collectMerchantFilters();
		payload.page = currentPage;

		$('#merchants-loading').removeClass('d-none');

		$.post(AJAX_URL, payload, function (res) {
			$('#merchants-loading').addClass('d-none');

			if (!res || !res.success) {
				showToast((res && res.data && res.data.message) || 'Не удалось загрузить мерчантов.', 'danger');
				return;
			}

			renderMerchantsTable(res.data.rows || []);
			renderMerchantsPagination(res.data.total_pages || 1, res.data.page || 1);
			$('#merchants-stats').text('Найдено: ' + (res.data.total || 0));
		}, 'json').fail(function () {
			$('#merchants-loading').addClass('d-none');
			showToast('Ошибка сервера при загрузке мерчантов.', 'danger');
		});
	}

	function renderActionMenu(row) {
		var html = '';
		html += '<div class="btn-group btn-group-sm">';
		if (CAN_EDIT) {
			html += '<button type="button" class="btn btn-default js-merchant-edit" data-id="' + row.id + '">Ред.</button>';
		}
		html += '<button type="button" class="btn btn-default dropdown-toggle' + (CAN_EDIT ? ' dropdown-toggle-split' : '') + '" data-bs-toggle="dropdown" aria-expanded="false"></button>';
		html += '<div class="dropdown-menu dropdown-menu-right">';
		if (CAN_INVITE) {
			html += '<a href="#" class="dropdown-item js-merchant-invite-history" data-id="' + row.id + '" data-name="' + escHtml(row.name || ('Merchant #' + row.id)) + '">История инвайтов</a>';
		}
		if (CAN_LEDGER) {
			html += '<a href="#" class="dropdown-item js-merchant-ledger" data-id="' + row.id + '" data-name="' + escHtml(row.name || ('Merchant #' + row.id)) + '">Ledger</a>';
		}
		if (CAN_ORDERS) {
			html += '<a href="#" class="dropdown-item js-merchant-orders" data-id="' + row.id + '" data-name="' + escHtml(row.name || ('Merchant #' + row.id)) + '">Ордера</a>';
		}
		if (CAN_BLOCK) {
			if (row.status === 'active') {
				html += '<a href="#" class="dropdown-item text-warning js-merchant-status" data-id="' + row.id + '" data-status="blocked">Заблокировать</a>';
			} else {
				html += '<a href="#" class="dropdown-item text-success js-merchant-status" data-id="' + row.id + '" data-status="active">Активировать</a>';
			}
			if (row.status !== 'archived') {
				html += '<a href="#" class="dropdown-item text-danger js-merchant-status" data-id="' + row.id + '" data-status="archived">Архивировать</a>';
			}
		}
		html += '</div></div>';
		return html;
	}

	function renderMerchantsTable(rows) {
		var colspan = IS_ROOT ? 11 : 10;
		var $tbody = $('#merchants-tbody').empty();

		if (!rows || !rows.length) {
			$tbody.html('<tr><td colspan="' + colspan + '" class="text-center p-t-30 p-b-30 text-muted">Ничего не найдено.</td></tr>');
			return;
		}

		rows.forEach(function (row) {
			var profileLine = $.trim([row.telegram_first_name || '', row.telegram_last_name || ''].join(' '));
			var nameHtml = '<div class="d-flex align-items-center">'
				+ merchantAvatarHtml(row.telegram_avatar_url || '', row.name || row.telegram_first_name || row.telegram_username || 'TG')
				+ '<div><div class="semi-bold">' + escHtml(row.name || profileLine || 'Без имени') + '</div>'
				+ '<div class="hint-text fs-12">ID #' + row.id + (profileLine ? ' · ' + escHtml(profileLine) : '') + '</div></div></div>';
			var tgHtml = row.telegram_username ? '@' + escHtml(row.telegram_username) : '<span class="hint-text">—</span>';
			var refHtml = row.referred_by_name
				? '<div class="hint-text fs-12">ref: ' + escHtml(row.referred_by_name) + '</div>'
				: '';
			var statusHtml = '<span class="badge badge-' + escHtml(row.status_badge) + '">' + escHtml(row.status_label) + '</span>';

			var html = '<tr>'
				+ '<td class="v-align-middle"><span class="hint-text fs-12">#' + row.id + '</span></td>'
				+ '<td class="v-align-middle">' + nameHtml + refHtml + '</td>'
				+ '<td class="v-align-middle"><code>' + escHtml(row.chat_id) + '</code></td>'
				+ '<td class="v-align-middle">' + tgHtml + '</td>';
			if (IS_ROOT) {
				html += '<td class="v-align-middle"><div>' + escHtml(row.company_name || '—') + '</div><div class="hint-text fs-12">' + escHtml(row.company_code || '') + '</div></td>';
			}
			html += '<td class="v-align-middle">' + statusHtml + '</td>'
				+ '<td class="v-align-middle">' + escHtml(row.base_markup_label) + '</td>'
				+ '<td class="v-align-middle font-montserrat fs-12">' + escHtml(row.bonus_balance_label) + '</td>'
				+ '<td class="v-align-middle font-montserrat fs-12">' + escHtml(row.referral_balance_label) + '</td>'
				+ '<td class="v-align-middle hint-text fs-12">' + escHtml(row.created_at || '—') + '</td>'
				+ '<td class="v-align-middle text-right">' + renderActionMenu(row) + '</td>'
				+ '</tr>';

			$tbody.append(html);
		});

		$('#merchants-tbody [data-bs-toggle="dropdown"]').each(function () {
			bootstrap.Dropdown.getOrCreateInstance(this, {
				popperConfig: function (config) {
					return $.extend(true, config, { strategy: 'fixed' });
				}
			});
		});
	}

	function renderMerchantsPagination(totalPages, current) {
		var $wrap = $('#merchants-pagination').empty();
		if (totalPages <= 1) {
			return;
		}
		var html = '<ul class="pagination pagination-sm no-margin">';
		html += '<li class="page-item' + (current <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (current - 1) + '">&laquo;</a></li>';
		for (var i = 1; i <= totalPages; i++) {
			html += '<li class="page-item' + (i === current ? ' active' : '') + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
		}
		html += '<li class="page-item' + (current >= totalPages ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (current + 1) + '">&raquo;</a></li>';
		html += '</ul>';
		$wrap.html(html);
	}

	function openCreateMerchantModal() {
		resetMerchantForm();
		setMerchantFormMode(false);
		if (!IS_ROOT) {
			syncMerchantFormOptions('<?php echo (int) $current_company_id; ?>', '', 0);
		}
		$('#merchant-modal').modal('show');
	}

	function openEditMerchantModal(id) {
		hideInlineAlert($('#merchant-form-alert'));
		$.get(AJAX_URL, { action: 'me_merchants_get', _nonce: NONCES.save, id: id }, function (res) {
			if (!res || !res.success) {
				showToast((res && res.data && res.data.message) || 'Не удалось загрузить мерчанта.', 'danger');
				return;
			}

			var row = res.data;
			resetMerchantForm();
			setMerchantFormMode(true);

			$('#merchant-id').val(row.id);
			$('#merchant-chat-id').val(row.chat_id);
			$('#merchant-telegram-username').val(row.telegram_username || '');
			$('#merchant-telegram-first-name').val(row.telegram_first_name || '');
			$('#merchant-telegram-last-name').val(row.telegram_last_name || '');
			$('#merchant-telegram-language-code').val(row.telegram_language_code || '');
			$('#merchant-name').val(row.name || '');
			setMerchantAvatarPreview(row.telegram_avatar_url || '', row.name || row.telegram_first_name || row.telegram_username || 'TG');
			$('#merchant-status').val(row.status).trigger('change.select2');
			$('#merchant-markup-type').val(row.base_markup_type).trigger('change.select2');
			$('#merchant-markup-value').val(row.base_markup_value);
			$('#merchant-ref-code').val(row.ref_code || '');
			$('#merchant-note').val(row.note || '');
			$('#merchant-company-id').val(String(row.company_id));
			syncMerchantFormOptions(row.company_id, row.referred_by_merchant_id || '', row.id);
			$('#merchant-modal').modal('show');
		}, 'json').fail(function () {
			showToast('Ошибка сервера при загрузке мерчанта.', 'danger');
		});
	}

	function saveMerchant() {
		var companyId = $('#merchant-company-id').val();
		var payload = {
			action:                 'me_merchants_save',
			_nonce:                 NONCES.save,
			merchant_id:            $('#merchant-id').val(),
			company_id:             companyId,
			chat_id:                $('#merchant-chat-id').val(),
			telegram_username:      $('#merchant-telegram-username').val(),
			telegram_first_name:    $('#merchant-telegram-first-name').val(),
			telegram_last_name:     $('#merchant-telegram-last-name').val(),
			telegram_language_code: $('#merchant-telegram-language-code').val(),
			name:                   $('#merchant-name').val(),
			status:                 $('#merchant-status').val(),
			base_markup_type:       $('#merchant-markup-type').val(),
			base_markup_value:      $('#merchant-markup-value').val(),
			ref_code:               $('#merchant-ref-code').val(),
			referred_by_merchant_id: $('#merchant-referred-by').val(),
			note:                   $('#merchant-note').val()
		};

		var $btn = $('#btn-save-merchant').prop('disabled', true);
		hideInlineAlert($('#merchant-form-alert'));

		$.post(AJAX_URL, payload, function (res) {
			$btn.prop('disabled', false);
			if (!res || !res.success) {
				showInlineAlert($('#merchant-form-alert'), (res && res.data && res.data.message) || 'Не удалось сохранить мерчанта.', 'danger');
				return;
			}
			$('#merchant-modal').modal('hide');
			showToast(res.data.message || 'Сохранено.', 'success');
			loadMerchants(currentPage);
		}, 'json').fail(function () {
			$btn.prop('disabled', false);
			showInlineAlert($('#merchant-form-alert'), 'Ошибка сервера при сохранении.', 'danger');
		});
	}

	function changeMerchantStatus(id, status) {
		var labels = { active: 'активировать', blocked: 'заблокировать', archived: 'архивировать' };
		if (!confirm('Подтвердите: ' + (labels[status] || 'изменить статус') + ' мерчанта?')) {
			return;
		}

		$.post(AJAX_URL, {
			action:      'me_merchants_status',
			_nonce:      NONCES.status,
			merchant_id: id,
			status:      status
		}, function (res) {
			if (!res || !res.success) {
				showToast((res && res.data && res.data.message) || 'Не удалось изменить статус.', 'danger');
				return;
			}
			showToast(res.data.message || 'Статус обновлён.', 'success');
			loadMerchants(currentPage);
		}, 'json').fail(function () {
			showToast('Ошибка сервера при смене статуса.', 'danger');
		});
	}

	function resetTelegramInvitePreview() {
		latestTelegramInvite = null;
		$('#telegram-invite-preview').addClass('d-none');
		$('#tg-invite-link-preview').val('');
		$('#tg-invite-preview-meta').text('—');
		$('#tg-invite-qr-preview-wrap').html('');
	}

	function renderTelegramInvitePreview(invite) {
		if (!invite || !invite.invite_url) {
			resetTelegramInvitePreview();
			return;
		}

		latestTelegramInvite = invite;
		$('#telegram-invite-preview').removeClass('d-none');
		$('#tg-invite-link-preview').val(invite.invite_url || '');
		$('#tg-invite-preview-meta').text('Payload: ' + (invite.telegram_start_payload || '—') + ' · Активен до: ' + (invite.expires_at || '—'));
		$('#tg-invite-qr-preview-wrap').html(
			invite.qr_url
				? '<img src="' + escHtml(invite.qr_url) + '" alt="" style="width:220px;height:220px;object-fit:contain;background:#fff;border:1px solid #e8edf2;border-radius:8px;padding:8px">'
				: '<div class="hint-text">QR ещё не готов.</div>'
		);
	}

	function renderTelegramInviteHistoryFocus() {
		var hasMerchantFocus = currentTelegramInviteMerchantId > 0;
		$('#btn-telegram-invite-history-clear-merchant').toggleClass('d-none', !hasMerchantFocus);
		$('#telegram-invite-history-focus-meta').text(
			hasMerchantFocus
				? 'Фокус на мерчанте: ' + (currentTelegramInviteMerchantName || ('Merchant #' + currentTelegramInviteMerchantId))
				: 'Все invite-ссылки компании'
		);
	}

	function clearTelegramInviteHistoryFocus() {
		currentTelegramInviteMerchantId = 0;
		currentTelegramInviteMerchantName = '';
		renderTelegramInviteHistoryFocus();
	}

	function focusTelegramInviteHistoryOnMerchant(id, name) {
		currentTelegramInviteMerchantId = parseInt(id, 10) || 0;
		currentTelegramInviteMerchantName = $.trim(String(name || '')) || ('Merchant #' + currentTelegramInviteMerchantId);
		renderTelegramInviteHistoryFocus();
		loadTelegramInviteHistory(1);

		var card = document.getElementById('telegram-invite-history-card');
		if (card && typeof card.scrollIntoView === 'function') {
			card.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	}

	function collectTelegramInviteHistoryFilters() {
		return {
			action: 'me_merchants_telegram_invites_history',
			_nonce: NONCES.invite,
			page: currentTelegramInvitePage,
			merchant_id: currentTelegramInviteMerchantId || '',
			per_page: $('#tg-invite-history-per-page').val(),
			search: $('#tg-invite-history-search').val(),
			status: $('#tg-invite-history-status').val(),
			date_from: $('#tg-invite-history-date-from').val(),
			date_to: $('#tg-invite-history-date-to').val()
		};
	}

	function renderTelegramInviteHistoryPagination(totalPages, current) {
		var $wrap = $('#telegram-invite-history-pagination').empty();
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

	function getTelegramInviteNowMs() {
		return Date.now() + telegramInviteServerOffsetMs;
	}

	function getTelegramInviteEffectiveStatus(row) {
		if (!row) {
			return 'new';
		}

		if (row.status !== 'new') {
			return row.status;
		}

		var expiresAtTs = parseInt(row.expires_at_ts || 0, 10);
		if (expiresAtTs > 0 && (expiresAtTs * 1000) <= getTelegramInviteNowMs()) {
			return 'expired';
		}

		return 'new';
	}

	function getTelegramInviteEffectiveMeta(row) {
		var status = getTelegramInviteEffectiveStatus(row);

		return {
			status: status,
			label: TELEGRAM_INVITE_STATUS_LABELS[status] || row.status_label || status,
			badge: TELEGRAM_INVITE_STATUS_BADGES[status] || row.status_badge || 'primary'
		};
	}

	function renderTelegramInviteHistoryActions(row) {
		var effective = getTelegramInviteEffectiveMeta(row);
		var items = [];

		if (row.invite_url) {
			items.push('<li><a class="dropdown-item telegram-invite-action-item js-copy-invite" href="#" data-link="' + escHtml(row.invite_url) + '"><i class="pg-icon telegram-invite-action-icon">copy</i><span>Скопировать</span></a></li>');
		}

		if (row.qr_url && effective.status === 'new') {
			items.push('<li><a class="dropdown-item telegram-invite-action-item js-show-telegram-invite-qr" href="#" data-id="' + row.id + '"><i class="pg-icon telegram-invite-action-icon">picture</i><span>Показать QR</span></a></li>');
		}

		if (effective.status === 'new') {
			items.push('<li><a class="dropdown-item telegram-invite-action-item text-danger js-invite-status" href="#" data-id="' + row.id + '" data-status="revoked"><i class="pg-icon telegram-invite-action-icon">close</i><span>Отозвать</span></a></li>');
		}

		if (!items.length) {
			return '<span class="hint-text">—</span>';
		}

		return ''
			+ '<div class="dropdown telegram-invite-actions">'
			+ '<button type="button" class="btn btn-default btn-xs dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Действия приглашения">'
			+ '<i class="pg-icon">more_vertical</i>'
			+ '</button>'
			+ '<ul class="dropdown-menu dropdown-menu-end">'
			+ items.join('')
			+ '</ul>'
			+ '</div>';
	}

	function renderTelegramInviteHistoryTable(rows) {
		var $tbody = $('#telegram-invite-history-tbody').empty();
		telegramInviteHistoryMap = {};
		if (!rows || !rows.length) {
			$tbody.html('<tr><td colspan="9" class="text-center text-muted p-t-25 p-b-25">Инвайтов пока нет.</td></tr>');
			return;
		}

		rows.forEach(function (row) {
			var effective = getTelegramInviteEffectiveMeta(row);
			telegramInviteHistoryMap[String(row.id)] = row;
			var merchantHtml = '<span class="hint-text">Ещё не активирован</span>';
			if (row.merchant_id) {
				merchantHtml = '<div class="d-flex align-items-center">'
					+ merchantAvatarHtml(row.merchant_avatar_url || '', row.merchant_name || row.merchant_chat_id || 'TG')
					+ '<div><div class="semi-bold">' + escHtml(row.merchant_name || ('Merchant #' + row.merchant_id)) + '</div>'
					+ '<div class="hint-text fs-12">' + escHtml(row.merchant_chat_id || row.used_by_chat_id || '—') + '</div></div></div>';
			} else if (row.used_by_chat_id) {
				merchantHtml = '<div class="semi-bold">chat_id ' + escHtml(row.used_by_chat_id) + '</div><div class="hint-text fs-12">Мерчант ещё не привязан в истории</div>';
			}

			var actions = renderTelegramInviteHistoryActions(row);

			$tbody.append(
				'<tr>'
				+ '<td>#' + row.id + '</td>'
				+ '<td>' + escHtml(row.created_by_name || '—') + '</td>'
				+ '<td>' + merchantHtml + '</td>'
				+ '<td><code>' + escHtml(row.telegram_start_payload || '—') + '</code></td>'
				+ '<td><span class="badge badge-' + escHtml(effective.badge) + '">' + escHtml(effective.label) + '</span></td>'
				+ '<td>' + escHtml((row.ttl_minutes || 0) + ' мин') + '</td>'
				+ '<td>' + escHtml(row.expires_at || '—') + '</td>'
				+ '<td>' + escHtml(row.created_at || '—') + '</td>'
				+ '<td class="text-center">' + actions + '</td>'
				+ '</tr>'
			);
		});

		$('#telegram-invite-history-tbody .telegram-invite-actions [data-bs-toggle="dropdown"]').each(function () {
			bootstrap.Dropdown.getOrCreateInstance(this, {
				popperConfig: function (config) {
					return $.extend(true, config, { strategy: 'fixed' });
				}
			});
		});
	}

	function refreshTelegramInviteHistoryUiState() {
		if (latestTelegramInvite && getTelegramInviteEffectiveStatus(latestTelegramInvite) !== 'new') {
			resetTelegramInvitePreview();
		}

		if (!currentTelegramInviteRows.length) {
			return;
		}

		renderTelegramInviteHistoryTable(currentTelegramInviteRows);
	}

	function loadTelegramInviteHistory(page) {
		currentTelegramInvitePage = page || 1;
		var payload = collectTelegramInviteHistoryFilters();
		payload.page = currentTelegramInvitePage;
		hideInlineAlert($('#telegram-invite-page-alert'));

		$.get(AJAX_URL, payload, function (res) {
			if (!res || !res.success) {
				showInlineAlert($('#telegram-invite-page-alert'), (res && res.data && res.data.message) || 'Не удалось загрузить историю invite-ссылок.', 'danger');
				return;
			}
			if (res.data.telegram_status) {
				renderTelegramInviteStatus(res.data.telegram_status);
			}
			currentTelegramInviteRows = res.data.rows || [];
			if (res.data.server_now_ts) {
				telegramInviteServerOffsetMs = (parseInt(res.data.server_now_ts, 10) * 1000) - Date.now();
			}
			renderTelegramInviteHistoryTable(currentTelegramInviteRows);
			renderTelegramInviteHistoryPagination(res.data.total_pages || 1, res.data.page || 1);
			var stats = 'Найдено: ' + (res.data.total || 0);
			if (currentTelegramInviteMerchantId > 0) {
				stats += ' · мерчант: ' + (currentTelegramInviteMerchantName || ('Merchant #' + currentTelegramInviteMerchantId));
			}
			$('#telegram-invite-history-stats').text(stats);
		}, 'json').fail(function () {
			showInlineAlert($('#telegram-invite-page-alert'), 'Ошибка сервера при загрузке истории invite-ссылок.', 'danger');
		});
	}

	function openTelegramInviteModal() {
		hideInlineAlert($('#telegram-invite-alert'));
		resetTelegramInvitePreview();
		renderTelegramInviteStatus(TELEGRAM_INVITE_STATUS);
		$('#merchant-telegram-invite-modal').modal('show');
	}

	function createTelegramInvite() {
		hideInlineAlert($('#telegram-invite-alert'));

		$.post(AJAX_URL, {
			action: 'me_merchants_telegram_invite_create',
			_nonce: NONCES.invite,
			base_markup_type: $('#tg-invite-markup-type').val(),
			base_markup_value: $('#tg-invite-markup-value').val(),
			note: $('#tg-invite-note').val()
		}, function (res) {
			if (!res || !res.success) {
				showInlineAlert($('#telegram-invite-alert'), (res && res.data && res.data.message) || 'Не удалось создать Telegram-инвайт.', 'danger');
				if (res && res.data && res.data.telegram_status) {
					renderTelegramInviteStatus(res.data.telegram_status);
				}
				return;
			}
			showInlineAlert($('#telegram-invite-alert'), res.data.message || 'Telegram-инвайт создан.', 'success');
			if (res.data.telegram_status) {
				renderTelegramInviteStatus(res.data.telegram_status);
			}
			renderTelegramInvitePreview(res.data.invite || null);
			clearTelegramInviteHistoryFocus();
			loadTelegramInviteHistory(1);
		}, 'json').fail(function () {
			showInlineAlert($('#telegram-invite-alert'), 'Ошибка сервера при создании Telegram-инвайта.', 'danger');
		});
	}

	function changeInviteStatus(inviteId, status) {
		var $activeAlert = $('#merchant-telegram-invite-modal').hasClass('show')
			? $('#telegram-invite-alert')
			: $('#telegram-invite-page-alert');

		$.post(AJAX_URL, {
			action:    'me_merchants_invite_status',
			_nonce:    NONCES.invite,
			invite_id: inviteId,
			status:    status
		}, function (res) {
			if (!res || !res.success) {
				showInlineAlert($activeAlert, (res && res.data && res.data.message) || 'Не удалось обновить статус приглашения.', 'danger');
				return;
			}
			showInlineAlert($activeAlert, res.data.message || 'Статус обновлён.', 'success');
			loadTelegramInviteHistory(currentTelegramInvitePage);
		}, 'json').fail(function () {
			showInlineAlert($activeAlert, 'Ошибка сервера при обновлении статуса приглашения.', 'danger');
		});
	}

	function openLedgerModal(id, name) {
		currentLedgerMerchant = { id: id, name: name || ('Merchant #' + id) };
		$('#merchant-ledger-title').text('Ledger: ' + currentLedgerMerchant.name);
		$('#merchant-ledger-modal').modal('show');
		loadLedger();
	}

	function loadLedger() {
		if (!currentLedgerMerchant) return;
		$.get(AJAX_URL, {
			action:      'me_merchants_ledger',
			_nonce:      NONCES.ledger,
			merchant_id: currentLedgerMerchant.id
		}, function (res) {
			if (!res || !res.success) {
				showToast((res && res.data && res.data.message) || 'Не удалось загрузить ledger.', 'danger');
				return;
			}
			$('#ledger-bonus-balance').text(res.data.summary.bonus_balance_label || '0 USDT');
			$('#ledger-referral-balance').text(res.data.summary.referral_balance_label || '0 USDT');
			$('#ledger-total-balance').text(res.data.summary.total_balance_label || '0 USDT');
			renderLedgerTable(res.data.rows || []);
		}, 'json').fail(function () {
			showToast('Ошибка сервера при загрузке ledger.', 'danger');
		});
	}

	function renderLedgerTable(rows) {
		var $tbody = $('#merchant-ledger-tbody').empty();
		if (!rows.length) {
			$tbody.html('<tr><td colspan="6" class="text-center text-muted p-t-25 p-b-25">Записей ledger пока нет.</td></tr>');
			return;
		}
		rows.forEach(function (row) {
			var source = '—';
			if (row.source_order_ref) {
				source = 'Order <code>' + escHtml(row.source_order_ref) + '</code>';
			} else if (row.source_merchant_name) {
				source = 'Merchant ' + escHtml(row.source_merchant_name);
			}
			$tbody.append(
				'<tr>'
				+ '<td>#' + row.id + '</td>'
				+ '<td>' + escHtml(row.entry_type_label) + '</td>'
				+ '<td class="font-montserrat">' + escHtml(row.amount_label) + '</td>'
				+ '<td>' + source + '</td>'
				+ '<td>' + escHtml(row.comment || '—') + '</td>'
				+ '<td>' + escHtml(row.created_at || '—') + '</td>'
				+ '</tr>'
			);
		});
	}

	function openOrdersModal(id, name) {
		currentOrdersMerchant = { id: id, name: name || ('Merchant #' + id) };
		$('#merchant-orders-title').text('Ордера: ' + currentOrdersMerchant.name);
		$('#merchant-orders-modal').modal('show');
		loadOrders();
	}

	function loadOrders() {
		if (!currentOrdersMerchant) return;
		$.get(AJAX_URL, {
			action:      'me_merchants_orders',
			_nonce:      NONCES.orders,
			merchant_id: currentOrdersMerchant.id
		}, function (res) {
			if (!res || !res.success) {
				showToast((res && res.data && res.data.message) || 'Не удалось загрузить ордера.', 'danger');
				return;
			}
			renderOrdersTable(res.data.rows || []);
		}, 'json').fail(function () {
			showToast('Ошибка сервера при загрузке ордеров.', 'danger');
		});
	}

	function renderOrdersTable(rows) {
		var $tbody = $('#merchant-orders-tbody').empty();
		if (!rows.length) {
			$tbody.html('<tr><td colspan="11" class="text-center text-muted p-t-25 p-b-25">Ордеров пока нет.</td></tr>');
			return;
		}
		rows.forEach(function (row) {
			$tbody.append(
				'<tr>'
				+ '<td>#' + row.id + '</td>'
				+ '<td>' + escHtml(row.created_at || '—') + '</td>'
				+ '<td>' + escHtml(row.provider_code || '—') + '</td>'
				+ '<td><code>' + escHtml(row.merchant_order_id || '—') + '</code></td>'
				+ '<td>' + escHtml(row.status_code || '—') + '</td>'
				+ '<td>' + escHtml(row.amount_asset_label || '—') + '</td>'
				+ '<td>' + escHtml(row.payment_amount_label || '—') + '</td>'
				+ '<td>' + escHtml(row.merchant_markup_label || '—') + '</td>'
				+ '<td>' + escHtml(row.platform_fee_label || '—') + '</td>'
				+ '<td>' + escHtml(row.merchant_profit_label || '—') + '</td>'
				+ '<td>' + escHtml(row.referral_reward_label || '—') + '</td>'
				+ '</tr>'
			);
		});
	}

	$('#btn-open-merchant-modal').on('click', function () {
		openCreateMerchantModal();
	});

	$('#btn-open-telegram-invite-modal').on('click', function () {
		openTelegramInviteModal();
	});

	$('#btn-create-telegram-invite').on('click', function () {
		createTelegramInvite();
	});

	$('#btn-copy-telegram-invite-link').on('click', function () {
		copyText($('#tg-invite-link-preview').val(), 'Ссылка Telegram-инвайта скопирована.');
	});

	$('#btn-save-merchant').on('click', function () {
		saveMerchant();
	});

	$('#merchant-company-id').on('change', function () {
		var companyId = $(this).val();
		var merchantId = $('#merchant-id').val() || 0;
		syncMerchantFormOptions(companyId, '', merchantId);
	});

	$('#btn-merchants-search').on('click', function () {
		loadMerchants(1);
	});

	$('#btn-merchants-reset').on('click', function () {
		$('#mf-search').val('');
		if (IS_ROOT) {
			$('#mf-company').val('').trigger('change.select2');
		}
		$('#mf-status').val('').trigger('change.select2');
		$('#mf-date-from').val('');
		$('#mf-date-to').val('');
		$('#mf-per-page').val('25').trigger('change.select2');
		loadMerchants(1);
	});

	$('#merchants-pagination').on('click', '.page-link', function (e) {
		e.preventDefault();
		var page = parseInt($(this).data('page'), 10);
		if (page > 0) {
			loadMerchants(page);
		}
	});

	$(document).on('click', '.js-merchant-edit', function () {
		openEditMerchantModal(parseInt($(this).data('id'), 10));
	});

	$(document).on('click', '.js-merchant-status', function (e) {
		e.preventDefault();
		changeMerchantStatus(parseInt($(this).data('id'), 10), String($(this).data('status') || ''));
	});

	$(document).on('click', '.js-merchant-invite-history', function (e) {
		e.preventDefault();
		focusTelegramInviteHistoryOnMerchant(parseInt($(this).data('id'), 10), $(this).data('name'));
	});

	$(document).on('click', '.js-merchant-ledger', function (e) {
		e.preventDefault();
		openLedgerModal(parseInt($(this).data('id'), 10), $(this).data('name'));
	});

	$(document).on('click', '.js-merchant-orders', function (e) {
		e.preventDefault();
		openOrdersModal(parseInt($(this).data('id'), 10), $(this).data('name'));
	});

	$(document).on('click', '.js-invite-status', function (e) {
		e.preventDefault();
		changeInviteStatus(parseInt($(this).data('id'), 10), String($(this).data('status') || ''));
	});

	$(document).on('click', '.js-copy-invite', function (e) {
		e.preventDefault();
		copyText($(this).data('link'), 'Ссылка приглашения скопирована.');
	});

	$(document).on('click', '.js-show-telegram-invite-qr', function (e) {
		e.preventDefault();
		var inviteId = parseInt($(this).data('id'), 10);
		if (!inviteId || !telegramInviteHistoryMap[String(inviteId)]) {
			return;
		}
		if (!$('#merchant-telegram-invite-modal').hasClass('show')) {
			openTelegramInviteModal();
		}
		renderTelegramInvitePreview(telegramInviteHistoryMap[String(inviteId)]);
	});

	$('#btn-telegram-invite-history-search').on('click', function () {
		loadTelegramInviteHistory(1);
	});

	$('#btn-telegram-invite-history-reset').on('click', function () {
		$('#tg-invite-history-search').val('');
		$('#tg-invite-history-status').val('').trigger('change.select2');
		$('#tg-invite-history-date-from').val('');
		$('#tg-invite-history-date-to').val('');
		$('#tg-invite-history-per-page').val('25').trigger('change.select2');
		clearTelegramInviteHistoryFocus();
		loadTelegramInviteHistory(1);
	});

	$('#btn-telegram-invite-history-clear-merchant').on('click', function () {
		clearTelegramInviteHistoryFocus();
		loadTelegramInviteHistory(1);
	});

	$('#telegram-invite-history-pagination').on('click', '.page-link', function (e) {
		e.preventDefault();
		var page = parseInt($(this).data('page'), 10);
		if (page > 0) {
			loadTelegramInviteHistory(page);
		}
	});

	document.addEventListener('visibilitychange', function () {
		if (!document.hidden) {
			refreshTelegramInviteHistoryUiState();
		}
	});

	$('#merchant-modal').on('shown.bs.modal', function () {
		$('#merchant-referred-by, #merchant-status, #merchant-markup-type, select#merchant-company-id').each(function () {
			var $el = $(this);
			if ($el.length && !$el.hasClass('select2-hidden-accessible')) {
				$el.select2({ dropdownParent: $('#merchant-modal') });
			}
		});
	});

	$('#merchant-modal').on('hidden.bs.modal', function () {
		$('#merchant-company-id').prop('disabled', false);
	});

	$('#merchant-telegram-invite-modal').on('shown.bs.modal', function () {
		$('#tg-invite-markup-type').each(function () {
			var $el = $(this);
			if ($el.length && !$el.hasClass('select2-hidden-accessible')) {
				$el.select2({ dropdownParent: $('#merchant-telegram-invite-modal') });
			}
		});
	});

	$('#tg-invite-history-status, #tg-invite-history-per-page').each(function () {
		var $el = $(this);
		if ($el.length && !$el.hasClass('select2-hidden-accessible')) {
			$el.select2();
		}
	});

	resetMerchantForm();
	renderTelegramInviteHistoryFocus();
	renderTelegramInviteStatus(TELEGRAM_INVITE_STATUS);
	setInterval(refreshTelegramInviteHistoryUiState, 15000);
	loadMerchants(1);
	if (!IS_ROOT) {
		loadTelegramInviteHistory(1);
	}

}(jQuery));
</script>
<?php
	},
	99
);
?>

<?php get_footer(); ?>

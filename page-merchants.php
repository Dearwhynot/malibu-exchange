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
$exchange_pair_definitions = function_exists( 'crm_company_exchange_pair_definitions' ) ? crm_company_exchange_pair_definitions() : [];
$exchange_pair_definitions = array_map(
	static function ( array $pair ): array {
		if ( (string) ( $pair['code'] ?? '' ) === 'RUB_USDT' ) {
			$pair['title'] = 'RUB/USDT';
			$pair['label'] = 'RUB -> USDT';
			$pair['hint']  = 'RUB/USDT направление для связанных расчётов и payout/fintech сценариев.';
		}

		return $pair;
	},
	$exchange_pair_definitions
);
$company_enabled_invoice_directions = function_exists( 'crm_company_get_enabled_invoice_directions' )
	? crm_company_get_enabled_invoice_directions( $current_company_id )
	: [];
$company_enabled_invoice_directions_map = array_fill_keys( $company_enabled_invoice_directions, true );
$merchant_feature_definitions = function_exists( 'crm_merchant_feature_definitions' )
	? crm_merchant_feature_definitions()
	: [];
$company_available_merchant_features = [];
if ( function_exists( 'crm_merchant_company_feature_available' ) ) {
	foreach ( array_keys( $merchant_feature_definitions ) as $feature_code ) {
		if ( crm_merchant_company_feature_available( $current_company_id, $feature_code ) ) {
			$company_available_merchant_features[] = $feature_code;
		}
	}
}

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

$nonce_list     = wp_create_nonce( 'me_merchants_list' );
$nonce_save     = wp_create_nonce( 'me_merchants_save' );
$nonce_status   = wp_create_nonce( 'me_merchants_status' );
$nonce_invite   = wp_create_nonce( 'me_merchants_invite' );
$nonce_ledger   = wp_create_nonce( 'me_merchants_ledger' );
$nonce_api_create = wp_create_nonce( 'me_merchant_api_client_create' );
$nonce_api_revoke = wp_create_nonce( 'me_merchant_api_client_revoke' );

$can_edit   = crm_can_access( 'merchants.edit' );
$can_block  = crm_can_access( 'merchants.block' );
$can_invite = crm_can_access( 'merchants.invite' );
$can_ledger = crm_can_access( 'merchants.ledger' );
$can_orders = crm_can_access( 'orders.view' );
$can_manage_api = function_exists( 'crm_can_manage_merchant_api' ) && crm_can_manage_merchant_api();

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
							<li class="breadcrumb-item active">Мерчанты</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">

				<div class="m-b-15">
					<h3 class="m-b-5">Мерчанты</h3>
					<p class="hint-text m-b-0">
						Company-scoped контур клиентов компании с invite-onboarding, ledger и привязкой к платёжным ордерам. Новые мерчанты появляются только после Telegram invite и запуска бота по персональной ссылке.
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
						<button type="button" id="btn-open-telegram-invite-modal" class="btn btn-primary" <?php echo empty( $telegram_invite_status['invite_ready'] ) ? 'disabled' : ''; ?>>
							<i class="pg-icon m-r-5">link</i>Создать инвайт
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
										<th style="width:150px">Обмен</th>
										<th style="width:120px">Наценка</th>
										<th style="width:130px">К выплате</th>
										<th style="width:120px">Бонус</th>
										<th style="width:120px">Рефка</th>
										<th style="width:160px">Создан</th>
										<th class="me-actions-col"></th>
									</tr>
								</thead>
								<tbody id="merchants-tbody">
									<tr>
										<td colspan="<?php echo $is_root ? 13 : 12; ?>" class="text-center p-t-30 p-b-30 text-muted">
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
										<th style="width:160px">Активен до</th>
										<th style="width:160px">Создан</th>
										<th class="me-actions-col me-actions-col-center"></th>
									</tr>
								</thead>
								<tbody id="telegram-invite-history-tbody">
									<tr>
										<td colspan="8" class="text-center text-muted p-t-25 p-b-25">Нажмите «Найти», чтобы загрузить историю invite-ссылок.</td>
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

		<?php get_template_part( 'template-parts/footer-backoffice' ); ?>
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

				<div class="card card-default m-b-20 merchant-invite-create-card">
					<div class="card-header">
						<div class="card-title">Создать Telegram-инвайт</div>
					</div>
					<div class="card-body">
						<form id="telegram-invite-create-form">
							<div class="merchant-invite-create-grid">
								<div class="merchant-invite-fields">
									<div class="merchant-invite-markup-grid">
										<div class="form-group">
											<label for="tg-invite-markup-basis">База расчёта</label>
											<select id="tg-invite-markup-basis" class="full-width" data-select2-hide-search="1">
												<?php foreach ( crm_merchant_markup_bases() as $basis_code => $label ) : ?>
												<option value="<?php echo esc_attr( $basis_code ); ?>"><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
											<div class="hint-text fs-12 m-t-5">Процент прибавляется либо к нашей себестоимости, либо к Rapira Ask.</div>
										</div>
										<div class="form-group">
											<label for="tg-invite-markup-type">Тип наценки</label>
											<select id="tg-invite-markup-type" class="full-width" data-select2-hide-search="1">
												<?php foreach ( crm_merchant_markup_types() as $type_code => $label ) : ?>
												<option value="<?php echo esc_attr( $type_code ); ?>"><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
										</div>
										<div class="form-group">
											<label for="tg-invite-markup-value">Значение наценки</label>
											<input type="number" class="form-control" id="tg-invite-markup-value" value="0" step="0.00000001" min="0">
										</div>
									</div>
									<div class="form-group merchant-invite-rub-mode-group">
										<label class="d-block m-b-8">RUB-сумма счёта</label>
										<div class="form-check form-check-inline m-r-20">
											<input type="radio"
											       class="form-check-input"
											       id="tg-invite-rub-mode-none"
											       name="tg_invite_rub_invoice_markup_mode"
											       value="none"
											       checked>
											<label class="form-check-label" for="tg-invite-rub-mode-none">Без надбавки</label>
										</div>
										<div class="form-check form-check-inline">
											<input type="radio"
											       class="form-check-input"
											       id="tg-invite-rub-mode-add"
											       name="tg_invite_rub_invoice_markup_mode"
											       value="add_on_top">
											<label class="form-check-label" for="tg-invite-rub-mode-add">Добавлять процент сверху</label>
										</div>
										<div class="hint-text fs-12 m-t-5">Отдельно: можно увеличивать сумму клиента в RUB на тот же процент.</div>
									</div>
									<div class="form-group merchant-directions-group">
										<label class="d-block m-b-8">Направления обмена мерчанта</label>
										<div class="hint-text fs-12 m-b-10">Можно выбрать только те направления, которые уже разрешены компании root-настройками.</div>
										<div class="row">
											<?php foreach ( $exchange_pair_definitions as $pair ) : ?>
												<?php
												$pair_code          = (string) ( $pair['code'] ?? '' );
												$pair_slug          = sanitize_html_class( strtolower( $pair_code ) );
												$is_company_enabled = isset( $company_enabled_invoice_directions_map[ $pair_code ] );
												?>
												<div class="col-md-6">
													<div class="merchant-direction-card">
														<div class="form-check complete m-b-5">
															<input type="checkbox"
															       id="tg-invite-direction-<?php echo esc_attr( $pair_slug ); ?>"
															       class="js-merchant-invite-direction"
															       value="<?php echo esc_attr( $pair_code ); ?>"
															       <?php checked( $is_company_enabled ); ?>
															       <?php disabled( ! $is_company_enabled ); ?>>
															<label for="tg-invite-direction-<?php echo esc_attr( $pair_slug ); ?>">
																<?php echo esc_html( (string) ( $pair['title'] ?? $pair_code ) ); ?>
															</label>
														</div>
														<div class="hint-text fs-12">
															<?php echo esc_html( $is_company_enabled ? (string) ( $pair['hint'] ?? '' ) : 'Сейчас это направление выключено на уровне компании.' ); ?>
														</div>
													</div>
												</div>
											<?php endforeach; ?>
										</div>
									</div>
									<div class="form-group merchant-invite-note-field">
										<label for="tg-invite-note">Заметка к будущему мерчанту</label>
										<textarea id="tg-invite-note" class="form-control" rows="2" placeholder="Например: трафик с группы Phuket / VIP поток"></textarea>
									</div>
								</div>
								<div class="merchant-invite-submit-field">
									<button type="button" id="btn-create-telegram-invite" class="btn btn-primary btn-block">
										<i class="pg-icon m-r-5">link</i>Создать инвайт
									</button>
								</div>
							</div>
						</form>
					</div>
				</div>

				<div id="telegram-invite-preview" class="card card-default m-b-20 merchant-invite-preview-card d-none">
					<div class="card-header">
						<div class="card-title">Последний созданный инвайт</div>
					</div>
					<div class="card-body">
						<div class="merchant-invite-preview-grid">
							<div class="merchant-invite-preview-main">
								<div class="form-group">
									<label for="tg-invite-link-preview">Ссылка</label>
									<div class="input-group merchant-invite-link-group">
										<input type="text" id="tg-invite-link-preview" class="form-control" readonly>
										<span class="input-group-btn">
											<button type="button" id="btn-copy-telegram-invite-link" class="btn btn-default">Копировать</button>
										</span>
									</div>
									<p class="hint-text m-t-5" id="tg-invite-preview-meta">—</p>
								</div>
							</div>
							<div class="merchant-invite-qr-column">
								<div class="merchant-invite-qr-panel text-center">
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
				<?php if ( $can_manage_api ) : ?>
				<ul class="nav nav-tabs nav-tabs-simple m-b-20" id="merchant-modal-tabs" role="tablist">
					<li class="nav-item">
						<a class="active" id="merchant-modal-tab-link-profile" data-bs-toggle="tab" role="tab" data-bs-target="#merchant-modal-tab-profile" href="#merchant-modal-tab-profile" aria-selected="true">
							Профиль
						</a>
					</li>
					<li class="nav-item">
						<a id="merchant-modal-tab-link-api" data-bs-toggle="tab" role="tab" data-bs-target="#merchant-modal-tab-api" href="#merchant-modal-tab-api" aria-selected="false">
							Merchant API
						</a>
					</li>
				</ul>
				<?php endif; ?>

				<div class="tab-content merchant-modal-tab-content">
					<div class="tab-pane active" id="merchant-modal-tab-profile" role="tabpanel">
						<div id="merchant-form-alert" class="alert d-none m-b-15" role="alert"></div>
						<form id="merchant-form">
					<input type="hidden" id="merchant-id" name="merchant_id" value="0">

					<?php if ( $is_root ) : ?>
					<div class="row">
						<div class="col-md-12">
							<div class="form-group">
								<label for="merchant-company-id">Компания</label>
								<select id="merchant-company-id" name="company_id" class="full-width">
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
								<select id="merchant-status" name="status" class="full-width" data-select2-hide-search="1">
									<?php foreach ( crm_merchant_statuses() as $status_code => $label ) : ?>
									<option value="<?php echo esc_attr( $status_code ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
					</div>

					<div class="row">
						<div class="col-md-6">
							<div class="form-group">
								<label for="merchant-markup-basis">База расчёта</label>
								<select id="merchant-markup-basis" name="base_markup_basis" class="full-width" data-select2-hide-search="1">
									<?php foreach ( crm_merchant_markup_bases() as $basis_code => $label ) : ?>
									<option value="<?php echo esc_attr( $basis_code ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<div class="hint-text fs-12 m-t-5">Процент прибавляется либо к нашей себестоимости, либо к Rapira Ask.</div>
							</div>
						</div>
						<div class="col-md-3">
							<div class="form-group">
								<label for="merchant-markup-type">Тип наценки</label>
								<select id="merchant-markup-type" name="base_markup_type" class="full-width" data-select2-hide-search="1">
									<?php foreach ( crm_merchant_markup_types() as $type_code => $label ) : ?>
									<option value="<?php echo esc_attr( $type_code ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div class="col-md-3">
							<div class="form-group">
								<label for="merchant-markup-value">Значение наценки</label>
								<input type="text" class="form-control" id="merchant-markup-value" name="base_markup_value" value="0">
							</div>
						</div>
					</div>

					<div class="row">
						<div class="col-md-4">
							<div class="form-group">
								<label for="merchant-ref-code">ref_code</label>
								<input type="text" class="form-control" id="merchant-ref-code" name="ref_code" placeholder="SURF-REF">
							</div>
						</div>
						<div class="col-md-8">
							<div class="form-group">
								<label for="merchant-referred-by">Кто его реферал-пригласитель</label>
								<select id="merchant-referred-by" name="referred_by_merchant_id" class="full-width">
									<option value="">Без реферера</option>
								</select>
							</div>
						</div>
					</div>

					<div class="row">
						<div class="col-md-12">
							<div class="form-group">
								<label class="d-block m-b-8">RUB-сумма счёта</label>
								<div class="form-check form-check-inline m-r-20">
									<input type="radio"
									       class="form-check-input"
									       id="merchant-rub-mode-none"
									       name="rub_invoice_markup_mode"
									       value="none"
									       checked>
									<label class="form-check-label" for="merchant-rub-mode-none">Без надбавки к сумме клиента</label>
								</div>
								<div class="form-check form-check-inline">
									<input type="radio"
									       class="form-check-input"
									       id="merchant-rub-mode-add"
									       name="rub_invoice_markup_mode"
									       value="add_on_top">
									<label class="form-check-label" for="merchant-rub-mode-add">Добавлять процент к сумме клиента</label>
								</div>
									<div class="hint-text fs-12 m-t-5">Отдельно: можно увеличивать сумму клиента в RUB на тот же процент.</div>
								</div>
							</div>
						</div>

					<div class="row">
						<div class="col-md-12">
							<div class="form-group merchant-directions-group">
								<label class="d-block m-b-8">Направления обмена мерчанта</label>
								<div class="hint-text fs-12 m-b-10">Ограничивает доступные контуры для конкретного мерчанта внутри текущей компании.</div>
								<div class="row">
									<?php foreach ( $exchange_pair_definitions as $pair ) : ?>
										<?php
										$pair_code          = (string) ( $pair['code'] ?? '' );
										$pair_slug          = sanitize_html_class( strtolower( $pair_code ) );
										$is_company_enabled = isset( $company_enabled_invoice_directions_map[ $pair_code ] );
										?>
										<div class="col-md-6">
											<div class="merchant-direction-card">
												<div class="form-check complete m-b-5">
													<input type="checkbox"
													       id="merchant-direction-<?php echo esc_attr( $pair_slug ); ?>"
													       class="js-merchant-direction"
													       value="<?php echo esc_attr( $pair_code ); ?>"
													       <?php checked( $is_company_enabled ); ?>
													       <?php disabled( ! $is_company_enabled ); ?>>
													<label for="merchant-direction-<?php echo esc_attr( $pair_slug ); ?>">
														<?php echo esc_html( (string) ( $pair['title'] ?? $pair_code ) ); ?>
													</label>
												</div>
												<div class="hint-text fs-12">
													<?php echo esc_html( $is_company_enabled ? (string) ( $pair['hint'] ?? '' ) : 'Сейчас это направление выключено на уровне компании.' ); ?>
												</div>
											</div>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					</div>

					<?php if ( ! empty( $merchant_feature_definitions ) ) : ?>
					<div class="row">
						<div class="col-md-12">
							<div class="form-group merchant-features-group">
								<label class="d-block m-b-8">Доступ к сервисам мерчанта</label>
								<div class="hint-text fs-12 m-b-10">Сервис должен быть включён для компании; здесь он выдаётся конкретному мерчанту.</div>
								<div class="row">
									<?php foreach ( $merchant_feature_definitions as $feature_code => $feature ) : ?>
										<?php
										$feature_slug         = sanitize_html_class( $feature_code );
										$is_company_available = in_array( $feature_code, $company_available_merchant_features, true );
										?>
										<div class="col-md-6">
											<div class="merchant-feature-card">
												<div class="form-check complete m-b-5">
													<input type="checkbox"
													       id="merchant-feature-<?php echo esc_attr( $feature_slug ); ?>"
													       class="js-merchant-feature"
													       value="<?php echo esc_attr( $feature_code ); ?>"
													       <?php disabled( ! $is_company_available ); ?>>
													<label for="merchant-feature-<?php echo esc_attr( $feature_slug ); ?>">
														<?php echo esc_html( (string) ( $feature['label'] ?? $feature_code ) ); ?>
													</label>
												</div>
												<div class="hint-text fs-12">
													<?php echo esc_html( $is_company_available ? (string) ( $feature['description'] ?? '' ) : 'Сначала root должен включить этот сервис для компании.' ); ?>
												</div>
												<?php if ( $feature_code === 'telegram_channels' ) : ?>
													<div class="m-t-15">
														<div class="row">
															<div class="col-md-12">
																<div class="form-group form-group-default form-group-default-select2">
																	<label for="merchant-telegram-markup-basis">База расчёта Telegram</label>
																	<select id="merchant-telegram-markup-basis" name="telegram_channels_markup_basis" class="full-width" data-select2-hide-search="1" <?php disabled( ! $is_company_available ); ?>>
																		<?php foreach ( crm_merchant_markup_bases() as $basis_code => $label ) : ?>
																		<option value="<?php echo esc_attr( $basis_code ); ?>"><?php echo esc_html( $label ); ?></option>
																		<?php endforeach; ?>
																	</select>
																</div>
															</div>
															<div class="col-md-6">
																<div class="form-group form-group-default form-group-default-select2">
																	<label for="merchant-telegram-markup-type">Тип</label>
																	<select id="merchant-telegram-markup-type" name="telegram_channels_markup_type" class="full-width" data-select2-hide-search="1" <?php disabled( ! $is_company_available ); ?>>
																		<?php foreach ( crm_merchant_markup_types() as $type_code => $label ) : ?>
																		<option value="<?php echo esc_attr( $type_code ); ?>"><?php echo esc_html( $label ); ?></option>
																		<?php endforeach; ?>
																	</select>
																</div>
															</div>
															<div class="col-md-6">
																<div class="form-group form-group-default">
																	<label for="merchant-telegram-markup-value">Значение</label>
																	<input type="text" class="form-control" id="merchant-telegram-markup-value" name="telegram_channels_markup_value" value="0" <?php disabled( ! $is_company_available ); ?>>
																</div>
															</div>
														</div>
														<div class="hint-text fs-12">Процент прибавляется к выбранной базе. Фиксированная сумма — RUB к курсу за 1 USDT.</div>
													</div>
												<?php endif; ?>
											</div>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					</div>
					<?php endif; ?>

					<div class="row">
						<div class="col-md-12">
							<div class="form-group">
								<label for="merchant-note">Заметка</label>
								<textarea id="merchant-note" name="note" class="form-control" rows="3" placeholder="Внутренняя заметка"></textarea>
							</div>
						</div>
					</div>
						</form>
					</div>

					<?php if ( $can_manage_api ) : ?>
					<div class="tab-pane" id="merchant-modal-tab-api" role="tabpanel">
						<div id="merchant-api-panel" class="merchant-modal-api-pane">
						<div id="merchant-api-alert" class="alert d-none m-b-15" role="alert"></div>
						<div id="merchant-api-token-host" class="alert alert-info d-none m-b-15" role="alert">
							<div class="semi-bold m-b-10">Новый Bearer token. Показывается только один раз.</div>
							<textarea id="merchant-api-token-output" class="form-control" rows="3" readonly></textarea>
							<div class="m-t-10">
								<label class="d-block fs-12 text-uppercase hint-text m-b-5" for="merchant-api-curl-output">Проверка endpoint</label>
								<textarea id="merchant-api-curl-output" class="form-control" rows="3" readonly></textarea>
							</div>
						</div>

						<div class="row g-2 align-items-end m-b-15">
							<div class="col-md-6">
								<div class="form-group m-b-0">
									<label for="merchant-api-client-name">Название интеграции</label>
									<input type="text" class="form-control" id="merchant-api-client-name" placeholder="Например, Main production">
								</div>
							</div>
							<div class="col-md-6 text-md-end">
								<button type="button" id="btn-create-merchant-api-client" class="btn btn-primary">
									<i class="pg-icon m-r-5">key</i>Выпустить ключ
								</button>
							</div>
						</div>

						<div id="merchant-api-mode-summary" class="hint-text fs-12 m-b-15">
							Режим компании будет показан после загрузки карточки мерчанта.
						</div>

						<div class="table-responsive">
							<table class="table table-hover m-b-0">
								<thead>
									<tr>
										<th style="width:220px">Интеграция</th>
										<th style="width:120px">Статус</th>
										<th style="width:180px">Token prefix</th>
										<th>Scopes</th>
										<th style="width:180px">Последний вызов</th>
										<th style="width:120px" class="text-right">Действия</th>
									</tr>
								</thead>
								<tbody id="merchant-api-clients-tbody">
									<tr>
										<td colspan="6" class="text-center text-muted p-t-20 p-b-20">Откройте карточку мерчанта, чтобы управлять Merchant API.</td>
									</tr>
								</tbody>
							</table>
						</div>
						</div>
					</div>
					<?php endif; ?>
				</div>
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
				<h4 class="modal-title" id="merchant-ledger-title">Баланс мерчанта</h4>
				<button type="button" class="close" data-bs-dismiss="modal" aria-label="Закрыть">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="modal-body">
				<div class="row m-b-15">
					<div class="col-md-3">
						<div class="card card-default p-15">
							<div class="small hint-text">К выплате</div>
							<div class="fs-16 bold" id="ledger-main-balance">0 USDT</div>
						</div>
					</div>
					<div class="col-md-3">
						<div class="card card-default p-15">
							<div class="small hint-text">Бонусный баланс</div>
							<div class="fs-16 bold" id="ledger-bonus-balance">0 USDT</div>
						</div>
					</div>
					<div class="col-md-3">
						<div class="card card-default p-15">
							<div class="small hint-text">Реферальный баланс</div>
							<div class="fs-16 bold" id="ledger-referral-balance">0 USDT</div>
						</div>
					</div>
					<div class="col-md-3">
						<div class="card card-default p-15">
							<div class="small hint-text">Итого</div>
							<div class="fs-16 bold" id="ledger-total-balance">0 USDT</div>
						</div>
					</div>
				</div>
				<div class="d-flex justify-content-between align-items-center m-b-10">
					<div class="semi-bold">Последние операции</div>
					<div class="hint-text fs-12">Показаны последние 30 движений</div>
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

<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>

<?php
add_action(
	'wp_footer',
	function () use ( $nonce_list, $nonce_save, $nonce_status, $nonce_invite, $nonce_ledger, $nonce_api_create, $nonce_api_revoke, $is_root, $can_edit, $can_block, $can_invite, $can_ledger, $can_orders, $can_manage_api, $companies_payload, $referrers_by_company, $current_company_id, $telegram_invite_status, $exchange_pair_definitions, $company_enabled_invoice_directions, $merchant_feature_definitions, $company_available_merchant_features ) {
	?>
<style>
#merchant-telegram-invite-modal .modal-dialog {
	max-width: 860px;
	width: calc(100% - 32px);
}
#merchant-telegram-invite-modal .modal-content {
	border: 0;
	border-radius: 6px;
	box-shadow: 0 18px 48px rgba(33, 33, 33, .22);
}
#merchant-telegram-invite-modal .modal-header {
	padding: 28px 32px 8px;
	border-bottom: 0;
}
#merchant-telegram-invite-modal .modal-title {
	font-size: 24px;
	line-height: 1.25;
	font-weight: 500;
}
#merchant-telegram-invite-modal .modal-body {
	padding: 0 32px 34px;
}
#merchant-telegram-invite-modal #telegram-invite-modal-status-host {
	margin-bottom: 28px;
}
#merchant-telegram-invite-modal #telegram-invite-modal-status-host .alert {
	margin-bottom: 0;
}
#merchant-telegram-invite-modal .merchant-invite-create-card,
#merchant-telegram-invite-modal .merchant-invite-preview-card {
	border: 0;
	box-shadow: none;
	background: transparent;
	margin-bottom: 34px;
}
#merchant-telegram-invite-modal .merchant-invite-create-card .card-header,
#merchant-telegram-invite-modal .merchant-invite-preview-card .card-header {
	padding: 0 0 12px;
	min-height: 0;
	border-bottom: 0;
	background: transparent;
}
#merchant-telegram-invite-modal .merchant-invite-create-card .card-title,
#merchant-telegram-invite-modal .merchant-invite-preview-card .card-title {
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
#merchant-telegram-invite-modal .merchant-invite-create-card .card-body,
#merchant-telegram-invite-modal .merchant-invite-preview-card .card-body {
	padding: 0;
}
#merchant-telegram-invite-modal label {
	margin-bottom: 6px;
	font-weight: 500;
	color: #555;
}
#merchant-telegram-invite-modal .merchant-invite-create-grid {
	display: grid;
	grid-template-columns: minmax(460px, 1fr) 224px;
	column-gap: 24px;
	align-items: end;
}
#merchant-telegram-invite-modal .merchant-invite-fields {
	min-width: 0;
}
#merchant-telegram-invite-modal .merchant-invite-markup-grid {
	display: grid;
	grid-template-columns: minmax(220px, 1.5fr) minmax(180px, 1fr) 176px;
	column-gap: 18px;
	margin-bottom: 14px;
}
#merchant-telegram-invite-modal .merchant-invite-create-grid .form-group {
	margin-bottom: 0;
}
#merchant-telegram-invite-modal #tg-invite-markup-value,
#merchant-telegram-invite-modal #btn-create-telegram-invite {
	height: 46px;
}
#merchant-telegram-invite-modal .select2-container .select2-selection--single {
	min-height: 46px;
}
#merchant-telegram-invite-modal .select2-container .select2-selection--single .select2-selection__rendered {
	line-height: 46px;
}
#merchant-telegram-invite-modal .select2-container .select2-selection--single .select2-selection__arrow {
	height: 46px;
}
#merchant-telegram-invite-modal #tg-invite-note {
	min-height: 76px;
	resize: vertical;
}
#merchant-telegram-invite-modal .merchant-directions-group,
#merchant-modal .merchant-directions-group,
#merchant-modal .merchant-features-group {
	margin-bottom: 16px;
}
#merchant-telegram-invite-modal .merchant-direction-card,
#merchant-modal .merchant-direction-card,
#merchant-modal .merchant-feature-card {
	height: 100%;
	padding: 12px 14px;
	border: 1px solid #e8edf2;
	border-radius: 10px;
	background: #f7fafc;
}
#merchant-telegram-invite-modal .merchant-direction-card .form-check,
#merchant-modal .merchant-direction-card .form-check,
#merchant-modal .merchant-feature-card .form-check {
	margin-bottom: 6px;
}
.merchant-direction-summary {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
}
.merchant-direction-stack {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 6px;
	min-width: 118px;
}
.merchant-direction-pill {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	margin-right: 0;
	padding: 4px 10px;
	border-radius: 999px;
	font-size: 11px;
	line-height: 1.25;
	font-weight: 600;
	letter-spacing: .02em;
	white-space: nowrap;
}
.merchant-direction-pill--rub-thb {
	background: #dff7ee;
	color: #0b8f68;
}
.merchant-direction-pill--usdt-thb {
	background: #e2efff;
	color: #2f6fd6;
}
.merchant-direction-pill--usdt-rub {
	background: #dff7f6;
	color: #0f8e96;
}
.merchant-direction-pill--muted {
	background: #eef4f8;
	color: #6c8194;
}
.merchant-direction-summary .badge-direction-compact-more {
	background: #edf7f4;
	color: #327c74;
}
.merchant-feature-summary {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	margin-top: 6px;
}
.merchant-feature-pill {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 4px 10px;
	border-radius: 999px;
	font-size: 11px;
	line-height: 1.25;
	font-weight: 600;
	white-space: nowrap;
	background: #fff3d9;
	color: #a26500;
}
.merchant-created-at {
	display: inline-flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 0;
	line-height: 1.4;
}
.merchant-created-at__date,
.merchant-created-at__time {
	display: block;
	white-space: nowrap;
}
#merchant-telegram-invite-modal #btn-create-telegram-invite {
	width: 100%;
	white-space: nowrap;
}
#merchant-telegram-invite-modal .merchant-invite-preview-grid {
	display: grid;
	grid-template-columns: minmax(390px, 1fr) 268px;
	column-gap: 32px;
	align-items: start;
}
#merchant-telegram-invite-modal .merchant-invite-preview-main {
	min-width: 0;
	padding-top: 1px;
}
#merchant-telegram-invite-modal .merchant-invite-preview-main .form-group {
	margin-bottom: 0;
}
#merchant-telegram-invite-modal .merchant-invite-link-group {
	display: grid !important;
	grid-template-columns: minmax(0, 1fr) 132px;
	width: 100%;
	align-items: stretch;
}
#merchant-telegram-invite-modal .merchant-invite-link-group .form-control {
	min-width: 0;
	width: 100%;
	height: 46px;
}
#merchant-telegram-invite-modal .merchant-invite-link-group .input-group-btn {
	display: block;
	width: 132px;
	white-space: nowrap;
}
#merchant-telegram-invite-modal .merchant-invite-link-group .btn {
	width: 100%;
	height: 46px;
	border-top-left-radius: 0;
	border-bottom-left-radius: 0;
}
#merchant-telegram-invite-modal #tg-invite-preview-meta {
	margin-top: 12px;
	min-height: 44px;
	line-height: 1.45;
	word-break: break-word;
}
#merchant-telegram-invite-modal .merchant-invite-qr-column {
	display: flex;
	justify-content: flex-end;
}
#merchant-telegram-invite-modal .merchant-invite-qr-panel {
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
#merchant-telegram-invite-modal #tg-invite-qr-preview-wrap {
	width: 232px;
	height: 232px;
	display: flex;
	align-items: center;
	justify-content: center;
}
#merchant-telegram-invite-modal #tg-invite-qr-preview-wrap img {
	width: 232px !important;
	height: 232px !important;
	object-fit: contain;
	background: #fff;
	border: 1px solid #e8edf2;
	border-radius: 8px;
	padding: 8px;
}
@media (max-width: 767px) {
	#merchant-telegram-invite-modal .modal-dialog {
		width: calc(100% - 20px);
	}
	#merchant-telegram-invite-modal .modal-header {
		padding: 22px 22px 8px;
	}
	#merchant-telegram-invite-modal .modal-body {
		padding: 0 22px 26px;
	}
	#merchant-telegram-invite-modal .merchant-invite-create-grid,
	#merchant-telegram-invite-modal .merchant-invite-preview-grid,
	#merchant-telegram-invite-modal .merchant-invite-markup-grid {
		grid-template-columns: minmax(0, 1fr);
		row-gap: 18px;
	}
	#merchant-telegram-invite-modal .merchant-invite-qr-column {
		justify-content: flex-start;
	}
	#merchant-telegram-invite-modal .merchant-invite-qr-panel {
		width: 100%;
	}
	#merchant-telegram-invite-modal .merchant-invite-link-group {
		grid-template-columns: minmax(0, 1fr) 122px;
	}
	#merchant-telegram-invite-modal .merchant-invite-link-group .input-group-btn {
		width: 122px;
	}
}
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
.row-action-menu .dropdown-menu {
	margin-top: 6px;
}
#merchant-modal .nav-tabs {
	margin-bottom: 18px;
}
#merchant-modal .nav-tabs ~ .tab-content {
	overflow: visible;
}
#merchant-modal .merchant-modal-tab-content > .tab-pane {
	padding: 0;
}
#merchant-modal #merchant-modal-tab-link-api.disabled {
	pointer-events: none;
	opacity: .45;
}
#merchant-modal .merchant-modal-api-pane {
	padding-top: 4px;
}
#merchant-modal #merchant-api-token-output,
#merchant-modal #merchant-api-curl-output {
	min-height: 84px;
	resize: vertical;
}
#merchant-modal #merchant-api-mode-summary {
	line-height: 1.65;
}
</style>
<script>
(function ($) {
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var ORDERS_PAGE_URL = '<?php echo esc_js( home_url( '/orders/' ) ); ?>';
	var NONCES = {
		list:       '<?php echo esc_js( $nonce_list ); ?>',
		save:       '<?php echo esc_js( $nonce_save ); ?>',
		status:     '<?php echo esc_js( $nonce_status ); ?>',
		invite:     '<?php echo esc_js( $nonce_invite ); ?>',
		ledger:     '<?php echo esc_js( $nonce_ledger ); ?>',
		apiCreate:  '<?php echo esc_js( $nonce_api_create ); ?>',
		apiRevoke:  '<?php echo esc_js( $nonce_api_revoke ); ?>'
	};
	var IS_ROOT    = <?php echo $is_root ? 'true' : 'false'; ?>;
	var CAN_EDIT   = <?php echo $can_edit ? 'true' : 'false'; ?>;
	var CAN_BLOCK  = <?php echo $can_block ? 'true' : 'false'; ?>;
	var CAN_INVITE = <?php echo $can_invite ? 'true' : 'false'; ?>;
	var CAN_LEDGER = <?php echo $can_ledger ? 'true' : 'false'; ?>;
	var CAN_ORDERS = <?php echo $can_orders ? 'true' : 'false'; ?>;
	var CAN_MANAGE_API = <?php echo $can_manage_api ? 'true' : 'false'; ?>;
	var EXCHANGE_PAIR_TITLES = <?php echo crm_json_for_inline_js( array_column( $exchange_pair_definitions, 'title', 'code' ) ); ?> || {};
	var COMPANY_ENABLED_DIRECTIONS = <?php echo crm_json_for_inline_js( array_values( array_map( 'strval', $company_enabled_invoice_directions ) ) ); ?> || [];
	var MERCHANT_FEATURE_DEFINITIONS = <?php echo crm_json_for_inline_js( $merchant_feature_definitions ); ?> || {};
	var COMPANY_AVAILABLE_MERCHANT_FEATURES = <?php echo crm_json_for_inline_js( array_values( array_map( 'strval', $company_available_merchant_features ) ) ); ?> || [];
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
	var currentMerchantApi = null;
	var latestTelegramInvite = null;
	var telegramInviteCreating = false;
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
		if (window.console && console.warn) {
			console.warn(message);
		}
	}

	function normalizeDirectionCodes(directions) {
		var seen = {};
		var normalized = [];

		$.each(directions || [], function (_, direction) {
			var code = $.trim(String(direction || '')).toUpperCase();
			if (code === 'THB_RUB') {
				code = 'RUB_THB';
			}
			if (code === 'USDT_RUB') {
				code = 'RUB_USDT';
			}
			if (!code || seen[code] || !EXCHANGE_PAIR_TITLES[code]) {
				return;
			}
			seen[code] = true;
			normalized.push(code);
		});

		return normalized;
	}

	function merchantDirectionLabels(directions) {
		return $.map(normalizeDirectionCodes(directions), function (code) {
			return EXCHANGE_PAIR_TITLES[code] || code;
		});
	}

	function merchantDirectionThemeClass(code) {
		var map = {
			RUB_THB: 'merchant-direction-pill--rub-thb',
			USDT_THB: 'merchant-direction-pill--usdt-thb',
			RUB_USDT: 'merchant-direction-pill--usdt-rub'
		};

		return map[code] || 'merchant-direction-pill--muted';
	}

	function renderMerchantDirectionPill(code, label, extraClass) {
		var classes = ['merchant-direction-pill', merchantDirectionThemeClass(code)];
		if (extraClass) {
			classes.push(extraClass);
		}

		return '<span class="' + classes.join(' ') + '">' + escHtml(label) + '</span>';
	}

	function renderMerchantDirectionCompact(directions) {
		var normalized = normalizeDirectionCodes(directions);
		var labels = merchantDirectionLabels(normalized);
		var title = escHtml(labels.join(', '));
		var html = '';

		if (!labels.length) {
			return '<span class="merchant-direction-pill merchant-direction-pill--muted">Нет направлений</span>';
		}

		html += '<span title="' + title + '">' + renderMerchantDirectionPill(normalized[0], labels[0]) + '</span>';
		if (labels.length > 1) {
			html += '<span class="merchant-direction-pill badge-direction-compact-more" title="' + title + '">+' + (labels.length - 1) + '</span>';
		}

		return html;
	}

	function renderMerchantDirectionColumn(directions) {
		var normalized = normalizeDirectionCodes(directions);
		var html = '';

		if (!normalized.length) {
			return '<div class="merchant-direction-stack"><span class="merchant-direction-pill merchant-direction-pill--muted">Нет направлений</span></div>';
		}

		$.each(normalized, function (_, code) {
			html += renderMerchantDirectionPill(code, EXCHANGE_PAIR_TITLES[code] || code);
		});

		return '<div class="merchant-direction-stack">' + html + '</div>';
	}

	function renderMerchantCreatedAt(value) {
		var raw = $.trim(String(value || ''));
		var parts;

		if (!raw || raw === '—') {
			return '<span class="hint-text">—</span>';
		}

		parts = raw.split(/\s+/, 2);
		if (parts.length < 2) {
			return '<div class="merchant-created-at"><span class="merchant-created-at__date">' + escHtml(raw) + '</span></div>';
		}

		return ''
			+ '<div class="merchant-created-at">'
			+ '<span class="merchant-created-at__date">' + escHtml(parts[0]) + '</span>'
			+ '<span class="merchant-created-at__time">' + escHtml(parts[1]) + '</span>'
			+ '</div>';
	}

	function setDirectionCheckboxes(selector, directions) {
		var normalized = normalizeDirectionCodes(directions);
		var companyAllowed = normalizeDirectionCodes(COMPANY_ENABLED_DIRECTIONS);

		$(selector).each(function () {
			var $checkbox = $(this);
			var code = String($checkbox.val() || '');
			var isCompanyAllowed = companyAllowed.indexOf(code) !== -1;
			$checkbox.prop('disabled', !isCompanyAllowed);
			$checkbox.prop('checked', isCompanyAllowed && normalized.indexOf(code) !== -1);
		});
	}

	function collectDirectionCheckboxes(selector) {
		var selected = [];
		$(selector + ':checked').each(function () {
			selected.push($(this).val());
		});

		return normalizeDirectionCodes(selected);
	}

	function normalizeMerchantFeatureCodes(features) {
		var items = [];
		var seen = {};
		var normalized = [];

		if ($.isArray(features)) {
			items = features;
		} else if (features && typeof features === 'object') {
			$.each(features, function (code, meta) {
				if (meta === true || (meta && meta.enabled)) {
					items.push(code);
				}
			});
		}

		$.each(items || [], function (_, featureCode) {
			var code = $.trim(String(featureCode || '')).toLowerCase();
			if (!code || seen[code] || !MERCHANT_FEATURE_DEFINITIONS[code]) {
				return;
			}
			seen[code] = true;
			normalized.push(code);
		});

		return normalized;
	}

	function setMerchantFeatureCheckboxes(featureAccess) {
		var enabled = normalizeMerchantFeatureCodes(featureAccess);

		$('.js-merchant-feature').each(function () {
			var $checkbox = $(this);
			var code = String($checkbox.val() || '');
			var isCompanyAvailable = COMPANY_AVAILABLE_MERCHANT_FEATURES.indexOf(code) !== -1;
			$checkbox.prop('disabled', !isCompanyAvailable);
			$checkbox.prop('checked', isCompanyAvailable && enabled.indexOf(code) !== -1);
		});
	}

	function collectMerchantFeatureCheckboxes() {
		var selected = [];
		$('.js-merchant-feature:checked:not(:disabled)').each(function () {
			selected.push($(this).val());
		});

		return normalizeMerchantFeatureCodes(selected);
	}

	function renderMerchantFeatureBadges(featureAccess) {
		var enabled = normalizeMerchantFeatureCodes(featureAccess);
		var html = '';

		$.each(enabled, function (_, code) {
			var definition = MERCHANT_FEATURE_DEFINITIONS[code] || {};
			html += '<span class="merchant-feature-pill">' + escHtml(definition.short_label || definition.label || code) + '</span>';
		});

		return html ? '<div class="merchant-feature-summary">' + html + '</div>' : '';
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

	function merchantAvatarInitials(label) {
		return (label || 'TG').replace(/\s+/g, ' ').trim().substring(0, 2).toUpperCase() || 'TG';
	}

	function merchantAvatarImage(url, size, alt) {
		var safeUrl = escHtml(url);
		var safeSize = parseInt(size, 10) || 40;
		var safeAlt = escHtml(alt || 'Profile Image');
		return '<img class="merchant-avatar-inline__image" src="' + safeUrl + '" data-src="' + safeUrl + '" data-src-retina="' + safeUrl + '" alt="' + safeAlt + '" width="' + safeSize + '" height="' + safeSize + '">';
	}

	function merchantAvatarHtml(url, label) {
		if (url) {
			return '<span class="thumbnail-wrapper circular inline m-r-10 merchant-avatar-inline">' + merchantAvatarImage(url, 40, label || 'Profile Image') + '</span>';
		}
		return '<span class="thumbnail-wrapper circular inline m-r-10 bg-complete text-white merchant-avatar-inline merchant-avatar-inline--fallback"><span>' + escHtml(merchantAvatarInitials(label)) + '</span></span>';
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
			return '<div class="alert alert-success bordered m-b-0"><strong>Создание Telegram invite-ссылок доступно.</strong><br>'
				+ (status.bot_handle ? 'Бот: ' + escHtml(status.bot_handle) + '. ' : '')
				+ 'Можно создавать deep-link, показывать QR и ждать запуск /start от мерчанта.</div>';
		}

		if (status.is_configured) {
			var html = '<div class="alert alert-warning bordered m-b-0"><strong>Создание новых Telegram invite-ссылок недоступно.</strong><br>'
				+ escHtml(status.blocked_reason || 'Сначала подключите callback для этой компании.');
			if (status.webhook_last_error) {
				html += '<div class="m-t-10"><strong>Последняя ошибка Telegram API:</strong> ' + escHtml(status.webhook_last_error) + '</div>';
			}
			html += '<div class="m-t-10">Откройте «Настройки», проверьте имя бота и токен, затем подключите callback. Существующие мерчанты и их статус не затрагиваются.</div></div>';
			return html;
		}

		var danger = '<div class="alert alert-danger bordered m-b-0"><strong>Создание Telegram invite-ссылок заблокировано.</strong><br>'
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
		$('#btn-create-telegram-invite').prop('disabled', !TELEGRAM_INVITE_STATUS.invite_ready || telegramInviteCreating);
		$('#btn-open-telegram-invite-modal')
			.prop('disabled', !TELEGRAM_INVITE_STATUS.invite_ready)
			.attr('title', TELEGRAM_INVITE_STATUS.invite_ready ? '' : (TELEGRAM_INVITE_STATUS.blocked_reason || 'Сначала подключите мерчантский Telegram callback в настройках.'));
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

	function setMerchantFormMode() {
		$('#merchant-modal-title').text('Редактировать мерчанта');
		if (IS_ROOT) {
			$('#merchant-company-id').prop('disabled', true);
			$('#merchant-company-hint').text('Компания существующего мерчанта не меняется.');
		}
	}

	function syncMerchantModalFooter(targetId) {
		var isApiTab = CAN_MANAGE_API && targetId === '#merchant-modal-tab-api';
		$('#btn-save-merchant').toggleClass('d-none', isApiTab);
	}

	function activateMerchantModalTab(targetId) {
		var $nav = $('#merchant-modal-tabs');
		if (!$nav.length || !targetId) {
			syncMerchantModalFooter('#merchant-modal-tab-profile');
			return;
		}

		var $links = $nav.find('a[data-bs-toggle="tab"]');
		var $panes = $('#merchant-modal-tab-profile, #merchant-modal-tab-api');
		var $activeLink = $links.filter('[data-bs-target="' + targetId + '"]');

		if (!$activeLink.length || $activeLink.hasClass('disabled')) {
			return;
		}

		$links.removeClass('active').attr('aria-selected', 'false');
		$links.parent('.nav-item').removeClass('active');
		$activeLink.addClass('active').attr('aria-selected', 'true');
		$activeLink.parent('.nav-item').addClass('active');

		$panes.removeClass('show active');
		$(targetId).addClass('show active');

		syncMerchantModalFooter(targetId);
	}

	function setMerchantApiTabEnabled(enabled) {
		var $apiTabLink = $('#merchant-modal-tab-link-api');
		if (!$apiTabLink.length) {
			return;
		}

		$apiTabLink.toggleClass('disabled', !enabled).attr('aria-disabled', enabled ? 'false' : 'true');

		if (!enabled && $apiTabLink.hasClass('active')) {
			activateMerchantModalTab('#merchant-modal-tab-profile');
		}
	}

	function resetMerchantApiPanel() {
		currentMerchantApi = null;
		hideInlineAlert($('#merchant-api-alert'));
		$('#merchant-api-token-host').addClass('d-none');
		$('#merchant-api-token-output').val('');
		$('#merchant-api-curl-output').val('');
		$('#merchant-api-client-name').val('');
		$('#btn-create-merchant-api-client').prop('disabled', true);
		$('#merchant-api-mode-summary').html('Режим компании будет показан после загрузки карточки мерчанта.');
		$('#merchant-api-clients-tbody').html('<tr><td colspan="6" class="text-center text-muted p-t-20 p-b-20">Откройте карточку мерчанта, чтобы управлять Merchant API.</td></tr>');
	}

	function renderMerchantApiModeSummary() {
		var summary = currentMerchantApi && currentMerchantApi.mode_summary ? currentMerchantApi.mode_summary : null;
		if (!summary || $.isEmptyObject(summary)) {
			$('#merchant-api-mode-summary').html('Режим компании для Merchant API пока не определён.');
			return;
		}

		var lines = [];
		lines.push(
			'<span class="semi-bold">Provider mode:</span> <code>' + escHtml(summary.provider_mode || '—') + '</code>'
			+ ' · <span class="semi-bold">Мерчант задаёт:</span> <code>' + escHtml(summary.requested_amount_currency || '—') + '</code>'
			+ ' · <span class="semi-bold">Клиент платит:</span> <code>' + escHtml(summary.payment_currency_code || '—') + '</code>'
			+ ' · <span class="semi-bold">Settlement:</span> <code>' + escHtml(summary.settlement_currency_code || '—') + '</code>'
		);

		if (summary.active_provider || summary.company_order_currency) {
			lines.push(
				'<span class="semi-bold">Провайдер:</span> <code>' + escHtml(summary.active_provider || '—') + '</code>'
				+ ' · <span class="semi-bold">Company order currency:</span> <code>' + escHtml(summary.company_order_currency || '—') + '</code>'
			);
		}

		if ($.isArray(summary.enabled_directions) && summary.enabled_directions.length) {
			var directionLabels = $.map(normalizeDirectionCodes(summary.enabled_directions), function (code) {
				return EXCHANGE_PAIR_TITLES[code] || code;
			});
			lines.push('<span class="semi-bold">Направления:</span> ' + escHtml(directionLabels.join(', ')));
		}

		$('#merchant-api-mode-summary').html(lines.join('<br>'));
	}

	function renderMerchantApiClients(payload) {
		currentMerchantApi = payload || null;
		renderMerchantApiModeSummary();

		var merchantId = parseInt($('#merchant-id').val(), 10) || 0;
		$('#btn-create-merchant-api-client').prop('disabled', merchantId <= 0);

		var clients = (currentMerchantApi && $.isArray(currentMerchantApi.clients)) ? currentMerchantApi.clients : [];
		var $tbody = $('#merchant-api-clients-tbody').empty();

		if (!clients.length) {
			$tbody.html('<tr><td colspan="6" class="text-center text-muted p-t-20 p-b-20">API-клиентов пока нет.</td></tr>');
			return;
		}

		clients.forEach(function (client) {
			var scopesLabel = $.isArray(client.scope_labels) && client.scope_labels.length
				? client.scope_labels.join(', ')
				: '—';
			var lastUsedLabel = client.last_used_at ? formatApiDate(client.last_used_at) : 'Не использовался';
			var createdLabel = client.created_at ? formatApiDate(client.created_at) : '—';
			var actionHtml = client.status !== 'revoked'
				? '<button type="button" class="btn btn-link text-danger p-0 fs-12 js-merchant-api-revoke" data-id="' + escHtml(client.id) + '">Отозвать</button>'
				: '<span class="hint-text fs-12">—</span>';

			var meta = [];
			meta.push('<span class="badge badge-' + escHtml(client.status_badge || 'secondary') + '">' + escHtml(client.status_label || client.status || '—') + '</span>');
			meta.push('<span class="hint-text fs-12">Создан: ' + escHtml(createdLabel) + '</span>');
			if (client.revoked_at) {
				meta.push('<span class="hint-text fs-12">Отозван: ' + escHtml(formatApiDate(client.revoked_at)) + '</span>');
			}

			var tokenMeta = '<code>' + escHtml(client.token_prefix || '—') + '</code>';
			if (client.webhook_url) {
				tokenMeta += '<div class="hint-text fs-12">Webhook: ' + escHtml(client.webhook_url) + '</div>';
			}

			$tbody.append(
				'<tr>'
				+ '<td class="v-align-middle"><div class="semi-bold">' + escHtml(client.client_name || 'Integration') + '</div><div class="hint-text fs-12">' + meta.join(' · ') + '</div></td>'
				+ '<td class="v-align-middle"><span class="badge badge-' + escHtml(client.status_badge || 'secondary') + '">' + escHtml(client.status_label || client.status || '—') + '</span></td>'
				+ '<td class="v-align-middle">' + tokenMeta + '</td>'
				+ '<td class="v-align-middle fs-12">' + escHtml(scopesLabel) + '</td>'
				+ '<td class="v-align-middle"><div class="fs-12">' + escHtml(lastUsedLabel) + '</div></td>'
				+ '<td class="v-align-middle text-right">' + actionHtml + '</td>'
				+ '</tr>'
			);
		});
	}

	function formatApiDate(value) {
		if (!value) {
			return '—';
		}
		var date = new Date(value);
		if (isNaN(date.getTime())) {
			return String(value);
		}
		return date.toLocaleString('ru-RU', {
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
			hour: '2-digit',
			minute: '2-digit'
		});
	}

	function createMerchantApiClient() {
		var merchantId = parseInt($('#merchant-id').val(), 10) || 0;
		var clientName = $.trim($('#merchant-api-client-name').val() || '');
		var $btn = $('#btn-create-merchant-api-client');

		hideInlineAlert($('#merchant-api-alert'));

		if (merchantId <= 0) {
			showInlineAlert($('#merchant-api-alert'), 'Сначала откройте существующего мерчанта.', 'warning');
			return;
		}

		$btn.prop('disabled', true);

		$.post(AJAX_URL, {
			action: 'me_merchant_api_client_create',
			_nonce: NONCES.apiCreate,
			merchant_id: merchantId,
			client_name: clientName
		}, function (res) {
			$btn.prop('disabled', false);

			if (!res || !res.success) {
				showInlineAlert($('#merchant-api-alert'), (res && res.data && res.data.message) || 'Не удалось выпустить Merchant API ключ.', 'danger');
				return;
			}

			showInlineAlert($('#merchant-api-alert'), res.data.message || 'Merchant API ключ выпущен.', 'success');
			$('#merchant-api-client-name').val('');
			$('#merchant-api-token-host').removeClass('d-none');
			$('#merchant-api-token-output').val((res.data && res.data.raw_token) || '');
			$('#merchant-api-curl-output').val((res.data && res.data.test_curl) || '');
			renderMerchantApiClients((res.data && res.data.merchant_api) || {});
		}, 'json').fail(function () {
			$btn.prop('disabled', false);
			showInlineAlert($('#merchant-api-alert'), 'Ошибка сервера при выпуске Merchant API ключа.', 'danger');
		});
	}

	function submitMerchantApiClientRevoke(clientId, merchantId) {
		hideInlineAlert($('#merchant-api-alert'));
		$.post(AJAX_URL, {
			action: 'me_merchant_api_client_revoke',
			_nonce: NONCES.apiRevoke,
			merchant_id: merchantId,
			client_id: clientId
		}, function (res) {
			if (!res || !res.success) {
				showInlineAlert($('#merchant-api-alert'), (res && res.data && res.data.message) || 'Не удалось отозвать Merchant API ключ.', 'danger');
				return;
			}

			showInlineAlert($('#merchant-api-alert'), res.data.message || 'Merchant API ключ отозван.', 'success');
			renderMerchantApiClients((res.data && res.data.merchant_api) || {});
		}, 'json').fail(function () {
			showInlineAlert($('#merchant-api-alert'), 'Ошибка сервера при отзыве Merchant API ключа.', 'danger');
		});
	}

	function revokeMerchantApiClient(clientId) {
		var merchantId = parseInt($('#merchant-id').val(), 10) || 0;
		if (merchantId <= 0 || !clientId) {
			showInlineAlert($('#merchant-api-alert'), 'Не удалось определить Merchant API client для отзыва.', 'danger');
			return;
		}

		if (window.confirm && !window.confirm('Подтвердите: отозвать Merchant API ключ?')) {
			return;
		}

		submitMerchantApiClientRevoke(clientId, merchantId);
	}

	function resetMerchantForm() {
		$('#merchant-form')[0].reset();
		hideInlineAlert($('#merchant-form-alert'));
		$('#merchant-id').val('0');
		$('#merchant-status').val('active').trigger('change.select2');
		$('#merchant-markup-basis').val('acquirer_cost').trigger('change.select2');
		$('#merchant-markup-type').val('percent').trigger('change.select2');
		$('#merchant-telegram-markup-basis').val('acquirer_cost').trigger('change.select2');
		$('#merchant-telegram-markup-type').val('percent').trigger('change.select2');
		$('#merchant-telegram-markup-value').val('0');
		$('input[name="rub_invoice_markup_mode"][value="none"]').prop('checked', true);
		setDirectionCheckboxes('.js-merchant-direction', COMPANY_ENABLED_DIRECTIONS);
		setMerchantFeatureCheckboxes({});
		setMerchantAvatarPreview('', 'TG');
		var companyId = IS_ROOT ? ($('#merchant-company-id').val() || '') : '<?php echo (int) $current_company_id; ?>';
		syncMerchantFormOptions(companyId, '', 0);
		resetMerchantApiPanel();
		setMerchantApiTabEnabled(false);
		activateMerchantModalTab('#merchant-modal-tab-profile');
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
		var hasSecondaryActions = CAN_INVITE || CAN_LEDGER || CAN_BLOCK;
		var html = '';
		if (!CAN_EDIT && !CAN_ORDERS && !hasSecondaryActions) {
			return '<span class="text-muted">Нет действий</span>';
		}

		html += '<div class="btn-group btn-group-sm row-action-menu">';
		html += '<button type="button" class="btn btn-default btn-xs dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Действия мерчанта">';
		html += '<i class="pg-icon">more_vertical</i>';
		html += '</button>';
		html += '<div class="dropdown-menu dropdown-menu-right">';
		if (CAN_ORDERS) {
			html += '<a href="#" class="dropdown-item js-merchant-orders" data-id="' + row.id + '">Ордера</a>';
		}
		if (CAN_EDIT) {
			html += '<a href="#" class="dropdown-item js-merchant-edit" data-id="' + row.id + '">Редактировать</a>';
		}
		if (CAN_INVITE) {
			html += '<a href="#" class="dropdown-item js-merchant-invite-history" data-id="' + row.id + '" data-name="' + escHtml(row.name || ('Merchant #' + row.id)) + '">История инвайтов</a>';
		}
		if (CAN_LEDGER) {
			html += '<a href="#" class="dropdown-item js-merchant-ledger" data-id="' + row.id + '" data-name="' + escHtml(row.name || ('Merchant #' + row.id)) + '">Баланс</a>';
		}
		if (CAN_BLOCK) {
			html += '<div class="dropdown-divider"></div>';
			if (row.status === 'active') {
				html += '<a href="#" class="dropdown-item text-warning js-merchant-status" data-id="' + row.id + '" data-status="blocked" data-current-status="' + escHtml(row.status || '') + '">Заблокировать</a>';
			} else {
				html += '<a href="#" class="dropdown-item text-success js-merchant-status" data-id="' + row.id + '" data-status="active" data-current-status="' + escHtml(row.status || '') + '">Активировать</a>';
			}
			if (row.status !== 'archived') {
				var archiveLabel = row.status === 'pending' ? 'Отменить запрос' : 'Архивировать';
				html += '<a href="#" class="dropdown-item text-danger js-merchant-status" data-id="' + row.id + '" data-status="archived" data-current-status="' + escHtml(row.status || '') + '" data-action-label="' + escHtml(archiveLabel) + '">' + archiveLabel + '</a>';
			}
		}
		html += '</div>';
		html += '</div>';
		return html;
	}

	function renderMerchantsTable(rows) {
		var colspan = IS_ROOT ? 13 : 12;
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
			var directionsCellHtml = renderMerchantDirectionColumn(row.enabled_invoice_directions || []);
			var featureBadgesHtml = renderMerchantFeatureBadges(row.feature_access || row.enabled_features || []);
			if (featureBadgesHtml) {
				directionsCellHtml += featureBadgesHtml;
			}

			var html = '<tr>'
				+ '<td class="v-align-middle"><span class="hint-text fs-12">#' + row.id + '</span></td>'
				+ '<td class="v-align-middle">' + nameHtml + refHtml + '</td>'
				+ '<td class="v-align-middle"><code>' + escHtml(row.chat_id) + '</code></td>'
				+ '<td class="v-align-middle">' + tgHtml + '</td>';
			if (IS_ROOT) {
				html += '<td class="v-align-middle"><div>' + escHtml(row.company_name || '—') + '</div><div class="hint-text fs-12">' + escHtml(row.company_code || '') + '</div></td>';
			}
			html += '<td class="v-align-middle">' + statusHtml + '</td>'
				+ '<td class="v-align-middle">' + directionsCellHtml + '</td>'
				+ '<td class="v-align-middle">' + escHtml(row.base_markup_label) + '</td>'
				+ '<td class="v-align-middle font-montserrat fs-12 semi-bold">' + escHtml(row.main_balance_label) + '</td>'
				+ '<td class="v-align-middle font-montserrat fs-12">' + escHtml(row.bonus_balance_label) + '</td>'
				+ '<td class="v-align-middle font-montserrat fs-12">' + escHtml(row.referral_balance_label) + '</td>'
				+ '<td class="v-align-middle hint-text fs-12">' + renderMerchantCreatedAt(row.created_at || '') + '</td>'
				+ '<td class="v-align-middle me-actions-col">' + renderActionMenu(row) + '</td>'
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

	function openEditMerchantModal(id) {
		hideInlineAlert($('#merchant-form-alert'));
		$.get(AJAX_URL, { action: 'me_merchants_get', _nonce: NONCES.save, id: id }, function (res) {
			if (!res || !res.success) {
				showToast((res && res.data && res.data.message) || 'Не удалось загрузить мерчанта.', 'danger');
				return;
			}

			var row = res.data;
			resetMerchantForm();
			setMerchantFormMode();

			$('#merchant-id').val(row.id);
			$('#merchant-chat-id').val(row.chat_id);
			$('#merchant-telegram-username').val(row.telegram_username || '');
			$('#merchant-telegram-first-name').val(row.telegram_first_name || '');
			$('#merchant-telegram-last-name').val(row.telegram_last_name || '');
			$('#merchant-telegram-language-code').val(row.telegram_language_code || '');
			$('#merchant-name').val(row.name || '');
			setMerchantAvatarPreview(row.telegram_avatar_url || '', row.name || row.telegram_first_name || row.telegram_username || 'TG');
			$('#merchant-status').val(row.status).trigger('change.select2');
			$('#merchant-markup-basis').val(row.base_markup_basis || 'acquirer_cost').trigger('change.select2');
			$('#merchant-markup-type').val(row.base_markup_type).trigger('change.select2');
			$('#merchant-telegram-markup-basis').val(row.telegram_channels_markup_basis || 'acquirer_cost').trigger('change.select2');
			$('#merchant-telegram-markup-type').val(row.telegram_channels_markup_type || 'percent').trigger('change.select2');
			$('#merchant-telegram-markup-value').val(row.telegram_channels_markup_value || '0');
			$('input[name="rub_invoice_markup_mode"][value="' + (row.rub_invoice_markup_mode || 'none') + '"]').prop('checked', true);
			setDirectionCheckboxes('.js-merchant-direction', row.enabled_invoice_directions || COMPANY_ENABLED_DIRECTIONS);
			setMerchantFeatureCheckboxes(row.feature_access || row.enabled_features || []);
			$('#merchant-markup-value').val(row.base_markup_value);
			$('#merchant-ref-code').val(row.ref_code || '');
			$('#merchant-note').val(row.note || '');
			$('#merchant-company-id').val(String(row.company_id));
			syncMerchantFormOptions(row.company_id, row.referred_by_merchant_id || '', row.id);
			if (CAN_MANAGE_API) {
				setMerchantApiTabEnabled(true);
				renderMerchantApiClients(row.merchant_api || {});
			}
			activateMerchantModalTab('#merchant-modal-tab-profile');
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
			base_markup_basis:      $('#merchant-markup-basis').val(),
			base_markup_type:       $('#merchant-markup-type').val(),
			rub_invoice_markup_mode: $('input[name="rub_invoice_markup_mode"]:checked').val() || 'none',
			telegram_channels_markup_basis: $('#merchant-telegram-markup-basis').val(),
			telegram_channels_markup_type:  $('#merchant-telegram-markup-type').val(),
			telegram_channels_markup_value: $('#merchant-telegram-markup-value').val(),
			enabled_invoice_directions: collectDirectionCheckboxes('.js-merchant-direction'),
			merchant_features:      collectMerchantFeatureCheckboxes(),
			base_markup_value:      $('#merchant-markup-value').val(),
			ref_code:               $('#merchant-ref-code').val(),
			referred_by_merchant_id: $('#merchant-referred-by').val(),
			note:                   $('#merchant-note').val()
		};

		hideInlineAlert($('#merchant-form-alert'));
		if (!payload.enabled_invoice_directions.length) {
			showInlineAlert($('#merchant-form-alert'), 'Выберите хотя бы одно направление обмена для мерчанта.', 'warning');
			return;
		}

		var $btn = $('#btn-save-merchant').prop('disabled', true);

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

	function changeMerchantStatus(id, status, currentStatus, actionLabel) {
		var labels = { active: 'активировать', blocked: 'заблокировать', archived: 'архивировать' };
		var effectiveLabel = actionLabel || labels[status] || 'изменить статус';
		var confirmText = status === 'archived' && currentStatus === 'pending'
			? 'Подтвердите: отменить запрос на активацию?'
			: 'Подтвердите: ' + effectiveLabel + ' мерчанта?';
		showConfirm(confirmText, function () {
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
		}, {
			btnClass: status === 'active' ? 'btn-success' : (status === 'archived' ? 'btn-danger' : 'btn-warning'),
			btnText: effectiveLabel ? effectiveLabel.charAt(0).toUpperCase() + effectiveLabel.slice(1) : 'Подтвердить'
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
		var directionLabels = $.map(normalizeDirectionCodes(invite.enabled_invoice_directions || []), function (code) {
			return EXCHANGE_PAIR_TITLES[code] || code;
		});
		var previewMeta = 'Payload: ' + (invite.telegram_start_payload || '—') + ' · Активен до: ' + (invite.expires_at || '—');
		if (directionLabels.length) {
			previewMeta += ' · Направления: ' + directionLabels.join(', ');
		}
		$('#tg-invite-preview-meta').text(previewMeta);
		$('#tg-invite-qr-preview-wrap').html(
			invite.qr_url
				? '<img src="' + escHtml(invite.qr_url) + '" alt="">'
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

		if (row.invite_url && effective.status === 'new') {
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
			$tbody.html('<tr><td colspan="8" class="text-center text-muted p-t-25 p-b-25">Инвайтов пока нет.</td></tr>');
			return;
		}

		rows.forEach(function (row) {
			var effective = getTelegramInviteEffectiveMeta(row);
			telegramInviteHistoryMap[String(row.id)] = row;
			var directionMeta = '<div class="merchant-direction-summary m-t-5"><span class="hint-text fs-11">Обмен:</span>' + renderMerchantDirectionCompact(row.enabled_invoice_directions || []) + '</div>';
			var merchantHtml = '<span class="hint-text">Ещё не активирован</span>';
			if (row.merchant_id) {
				merchantHtml = '<div class="d-flex align-items-center">'
					+ merchantAvatarHtml(row.merchant_avatar_url || '', row.merchant_name || row.merchant_chat_id || 'TG')
					+ '<div><div class="semi-bold">' + escHtml(row.merchant_name || ('Merchant #' + row.merchant_id)) + '</div>'
					+ '<div class="hint-text fs-12">' + escHtml(row.merchant_chat_id || row.used_by_chat_id || '—') + '</div>'
					+ directionMeta + '</div></div>';
			} else if (row.used_by_chat_id) {
				merchantHtml = '<div class="semi-bold">chat_id ' + escHtml(row.used_by_chat_id) + '</div><div class="hint-text fs-12">Мерчант ещё не привязан в истории</div>' + directionMeta;
			} else {
				merchantHtml = '<span class="hint-text">Ещё не активирован</span>' + directionMeta;
			}

			var actions = renderTelegramInviteHistoryActions(row);

			$tbody.append(
				'<tr>'
				+ '<td>#' + row.id + '</td>'
				+ '<td>' + escHtml(row.created_by_name || '—') + '</td>'
				+ '<td>' + merchantHtml + '</td>'
				+ '<td><code>' + escHtml(row.telegram_start_payload || '—') + '</code></td>'
				+ '<td><span class="badge badge-' + escHtml(effective.badge) + '">' + escHtml(effective.label) + '</span></td>'
				+ '<td>' + escHtml(row.expires_at || '—') + '</td>'
				+ '<td>' + escHtml(row.created_at || '—') + '</td>'
				+ '<td class="me-actions-col me-actions-col-center">' + actions + '</td>'
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
		if (!TELEGRAM_INVITE_STATUS.invite_ready) {
			showInlineAlert(
				$('#telegram-invite-page-alert'),
				TELEGRAM_INVITE_STATUS.blocked_reason || 'Создание инвайта недоступно: сначала подключите мерчантский Telegram callback в настройках.',
				'warning'
			);
			return;
		}
		$('#merchant-telegram-invite-modal').modal('show');
	}

	function createTelegramInvite() {
		if (telegramInviteCreating) {
			return;
		}

		hideInlineAlert($('#telegram-invite-alert'));
		var enabledDirections = collectDirectionCheckboxes('.js-merchant-invite-direction');
		if (!enabledDirections.length) {
			showInlineAlert($('#telegram-invite-alert'), 'Выберите хотя бы одно направление обмена для invite.', 'warning');
			return;
		}

		telegramInviteCreating = true;
		$('#btn-create-telegram-invite').prop('disabled', true).addClass('disabled');

		$.post(AJAX_URL, {
			action: 'me_merchants_telegram_invite_create',
			_nonce: NONCES.invite,
			base_markup_basis: $('#tg-invite-markup-basis').val(),
			base_markup_type: $('#tg-invite-markup-type').val(),
			rub_invoice_markup_mode: $('input[name="tg_invite_rub_invoice_markup_mode"]:checked').val() || 'none',
			enabled_invoice_directions: enabledDirections,
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
			$('#merchant-telegram-invite-modal').modal('hide');
			clearTelegramInviteHistoryFocus();
			loadTelegramInviteHistory(1);
			showInlineAlert($('#telegram-invite-page-alert'), res.data.message || 'Telegram-инвайт создан. Ссылка добавлена в историю.', 'success');
		}, 'json').fail(function () {
			showInlineAlert($('#telegram-invite-alert'), 'Ошибка сервера при создании Telegram-инвайта.', 'danger');
		}).always(function () {
			telegramInviteCreating = false;
			$('#btn-create-telegram-invite')
				.prop('disabled', !TELEGRAM_INVITE_STATUS.invite_ready)
				.removeClass('disabled');
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
		$('#merchant-ledger-title').text('Баланс: ' + currentLedgerMerchant.name);
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
				showToast((res && res.data && res.data.message) || 'Не удалось загрузить баланс.', 'danger');
				return;
			}
			$('#ledger-main-balance').text(res.data.summary.main_balance_label || '0 USDT');
			$('#ledger-bonus-balance').text(res.data.summary.bonus_balance_label || '0 USDT');
			$('#ledger-referral-balance').text(res.data.summary.referral_balance_label || '0 USDT');
			$('#ledger-total-balance').text(res.data.summary.total_balance_label || '0 USDT');
			renderLedgerTable(res.data.rows || []);
		}, 'json').fail(function () {
			showToast('Ошибка сервера при загрузке баланса.', 'danger');
		});
	}

	function renderLedgerTable(rows) {
		var $tbody = $('#merchant-ledger-tbody').empty();
		if (!rows.length) {
			$tbody.html('<tr><td colspan="6" class="text-center text-muted p-t-25 p-b-25">Операций пока нет.</td></tr>');
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

	function openMerchantOrdersPage(id) {
		var merchantId = parseInt(id, 10) || 0;
		if (!merchantId) {
			showToast('Не удалось определить мерчанта для перехода к ордерам.', 'warning');
			return;
		}

		window.location.assign(ORDERS_PAGE_URL + '?' + $.param({
			merchant_id: merchantId,
			contour: 'merchant'
		}));
	}

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

	$('#btn-create-merchant-api-client').on('click', function () {
		createMerchantApiClient();
	});

	$(document).on('click.me-merchant-tabs', '#merchant-modal-tabs a[data-bs-toggle="tab"]', function (e) {
		e.preventDefault();
		var $link = $(this);
		if ($link.hasClass('disabled')) {
			return;
		}
		activateMerchantModalTab($link.attr('data-bs-target') || $link.attr('href'));
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
		changeMerchantStatus(
			parseInt($(this).data('id'), 10),
			String($(this).data('status') || ''),
			String($(this).data('current-status') || ''),
			String($(this).data('action-label') || '')
		);
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
		openMerchantOrdersPage($(this).data('id'));
	});

	$(document).on('click', '.js-merchant-api-revoke', function () {
		revokeMerchantApiClient(parseInt($(this).data('id'), 10) || 0);
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

	function initModalSelect2($elements, $dropdownParent) {
		$elements.each(function () {
			var $el = $(this);
			var config = {
				dropdownParent: $dropdownParent,
				width: '100%'
			};
			if (!$el.length) {
				return;
			}
			if ($el.hasClass('select2-hidden-accessible')) {
				$el.select2('destroy');
			}
			if ($el.data('select2-hide-search')) {
				config.minimumResultsForSearch = Infinity;
			}
			$el.select2(config);
		});
	}

	function destroyModalSelect2($elements) {
		$elements.each(function () {
			var $el = $(this);
			if ($el.hasClass('select2-hidden-accessible')) {
				$el.select2('destroy');
			}
		});
	}

	$('#merchant-modal').on('shown.bs.modal', function () {
		initModalSelect2(
			$('#merchant-referred-by, #merchant-status, #merchant-markup-basis, #merchant-markup-type, #merchant-telegram-markup-basis, #merchant-telegram-markup-type, select#merchant-company-id'),
			$('#merchant-modal')
		);
		syncMerchantModalFooter($('#merchant-modal-tab-api').hasClass('active') ? '#merchant-modal-tab-api' : '#merchant-modal-tab-profile');
	});

	$('#merchant-modal').on('hidden.bs.modal', function () {
		destroyModalSelect2(
			$('#merchant-referred-by, #merchant-status, #merchant-markup-basis, #merchant-markup-type, #merchant-telegram-markup-basis, #merchant-telegram-markup-type, select#merchant-company-id')
		);
		$('#merchant-company-id').prop('disabled', false);
		setMerchantApiTabEnabled(false);
		activateMerchantModalTab('#merchant-modal-tab-profile');
		$('#btn-save-merchant').removeClass('d-none');
	});

	$('#merchant-telegram-invite-modal').on('shown.bs.modal', function () {
		initModalSelect2(
			$('#tg-invite-markup-basis, #tg-invite-markup-type'),
			$('#merchant-telegram-invite-modal')
		);
	});

	$('#merchant-telegram-invite-modal').on('hidden.bs.modal', function () {
		destroyModalSelect2($('#tg-invite-markup-basis, #tg-invite-markup-type'));
	});

	$('#tg-invite-history-status, #tg-invite-history-per-page').each(function () {
		var $el = $(this);
		if ($el.length && !$el.hasClass('select2-hidden-accessible')) {
			$el.select2();
		}
	});

	resetMerchantForm();
	setDirectionCheckboxes('.js-merchant-invite-direction', COMPANY_ENABLED_DIRECTIONS);
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

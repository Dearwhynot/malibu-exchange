<?php
/*
Template Name: Telegram Channels Page
Slug: telegram-channels
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

$current_uid = get_current_user_id();
if ( crm_is_root( $current_uid ) ) {
	malibu_exchange_render_root_company_scope_denied();
}

if ( ! crm_user_has_permission( $current_uid, 'telegram_channels.view' ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$company_id = crm_get_current_user_company_id( $current_uid );
if ( $company_id <= 0 ) {
	wp_die( 'Аккаунт не привязан к активной компании.' );
}

if ( ! function_exists( 'crm_company_contour_is_enabled' ) || ! crm_company_contour_is_enabled( $company_id, 'telegram_channels' ) ) {
	wp_die( 'Модуль Telegram-каналы выключен для этой компании.' );
}

crm_telegram_channels_seed_company_foundation( $company_id );

global $wpdb;

$company = crm_get_company_by_id( $company_id );
$merchant_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, name, telegram_username, chat_id, status
		 FROM crm_merchants
		 WHERE company_id = %d
		 ORDER BY name ASC, id ASC",
		$company_id
	)
) ?: [];
$merchant_options = [];
foreach ( $merchant_rows as $merchant_row ) {
	$merchant_id = (int) $merchant_row->id;
	$feature_enabled = function_exists( 'crm_merchant_has_feature_access' )
		&& crm_merchant_has_feature_access( $company_id, $merchant_id, 'telegram_channels' );
	$merchant_options[] = [
		'id'              => $merchant_id,
		'name'            => (string) ( $merchant_row->name ?? '' ),
		'username'        => (string) ( $merchant_row->telegram_username ?? '' ),
		'chat_id'         => (string) ( $merchant_row->chat_id ?? '' ),
		'status'          => (string) ( $merchant_row->status ?? '' ),
		'feature_enabled' => $feature_enabled,
	];
}

$requested_merchant_id = max( 0, (int) ( $_GET['merchant_id'] ?? 0 ) );
$selected_merchant_id = 0;
foreach ( $merchant_options as $merchant_option ) {
	if ( empty( $merchant_option['feature_enabled'] ) || (string) $merchant_option['status'] !== 'active' ) {
		continue;
	}
	if ( $requested_merchant_id > 0 && (int) $merchant_option['id'] === $requested_merchant_id ) {
		$selected_merchant_id = (int) $merchant_option['id'];
		break;
	}
	if ( $selected_merchant_id <= 0 ) {
		$selected_merchant_id = (int) $merchant_option['id'];
	}
}

if ( $selected_merchant_id > 0 ) {
	crm_telegram_channels_seed_merchant_foundation( $company_id, $selected_merchant_id );
}

$channel       = $selected_merchant_id > 0 ? crm_telegram_channels_get_merchant_channel( $company_id, $selected_merchant_id ) : null;
$tariffs       = $channel ? crm_telegram_channels_get_company_tariffs( $company_id, (int) $channel->id ) : [];
$readiness     = $selected_merchant_id > 0
	? crm_telegram_channels_get_readiness_status( $company_id, false, $selected_merchant_id )
	: [
		'is_ready' => false,
		'issues'   => [
			[
				'id'    => 'merchant_id',
				'label' => 'Нет активных мерчантов с доступом к Telegram-каналам.',
			],
		],
	];
$texts         = $selected_merchant_id > 0
	? crm_telegram_channels_get_texts( $company_id, $selected_merchant_id )
	: crm_telegram_channels_default_texts();
$subscribers   = $selected_merchant_id > 0 ? crm_telegram_channels_list_subscribers( $company_id, 50, $selected_merchant_id, $channel ? (int) $channel->id : 0 ) : [];
$payments      = $selected_merchant_id > 0 ? crm_telegram_channels_list_payments( $company_id, 50, $selected_merchant_id, $channel ? (int) $channel->id : 0 ) : [];
$telegram_status = $selected_merchant_id > 0
	? crm_telegram_channels_merchant_subscription_status( $company_id, $selected_merchant_id )
	: [];
$settings = is_array( $telegram_status['settings'] ?? null )
	? $telegram_status['settings']
	: [
		'token_set'         => false,
		'bot_token_masked'  => '',
		'bot_username'      => '',
		'reminders_enabled' => true,
		'reminder_days'     => '3',
		'invite_ttl_hours'  => '24',
	];
$identity = is_array( $telegram_status['identity'] ?? null )
	? $telegram_status['identity']
	: (
		function_exists( 'crm_telegram_channels_default_bot_identity' )
			? crm_telegram_channels_default_bot_identity( $company_id, $selected_merchant_id )
			: [
				'name'                 => '',
				'short_description'    => '',
				'description'          => '',
				'language_code'        => '',
				'menu_button'          => 'commands',
				'default_admin_rights' => true,
				'applied_at'           => '',
				'photo_applied_at'     => '',
				'last_error'           => '',
				'promo_image_url'      => '',
				'promo_image_uploaded_at' => '',
			]
	);
$identity_language_options = function_exists( 'crm_telegram_channels_identity_language_options' )
	? crm_telegram_channels_identity_language_options()
	: [ '' => 'Все языки' ];
$nonce = wp_create_nonce( 'me_telegram_channels' );

$can_settings = crm_user_has_permission( $current_uid, 'telegram_channels.settings' );
$can_tariffs  = crm_user_has_permission( $current_uid, 'telegram_channels.tariffs' );
$can_manage_subscribers = crm_user_has_permission( $current_uid, 'telegram_channels.manage_subscribers' );

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
							<li class="breadcrumb-item active">Telegram-каналы</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">
				<?php get_template_part( 'template-parts/toast-host' ); ?>

				<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 m-b-20">
					<div>
						<h3 class="m-b-5">Telegram-каналы</h3>
						<p class="hint-text m-b-0"><?php echo esc_html( $company ? (string) $company->name : 'Компания #' . $company_id ); ?> · paid subscription module</p>
					</div>
					<span id="tgch-readiness-badge" class="badge <?php echo ! empty( $readiness['is_ready'] ) ? 'badge-success' : 'badge-warning'; ?>">
						<?php echo ! empty( $readiness['is_ready'] ) ? 'Готов к публичному flow' : 'Требует настройки'; ?>
					</span>
				</div>

				<div class="card card-default m-b-20">
					<div class="card-body">
						<div class="row align-items-end">
							<div class="col-lg-8">
								<div class="form-group form-group-default form-group-default-select2 m-b-0">
									<label>Мерчант</label>
									<select id="tgch-merchant-select" class="full-width" data-init-plugin="select2">
										<?php if ( empty( $merchant_options ) ) : ?>
											<option value="">Мерчанты не найдены</option>
										<?php else : ?>
											<?php foreach ( $merchant_options as $merchant_option ) : ?>
												<?php
												$merchant_enabled = ! empty( $merchant_option['feature_enabled'] ) && (string) $merchant_option['status'] === 'active';
												$merchant_label = trim( (string) $merchant_option['name'] );
												if ( $merchant_label === '' ) {
													$merchant_label = 'Merchant #' . (int) $merchant_option['id'];
												}
												if ( ! empty( $merchant_option['username'] ) ) {
													$merchant_label .= ' · @' . ltrim( (string) $merchant_option['username'], '@' );
												}
												?>
												<option value="<?php echo (int) $merchant_option['id']; ?>" <?php selected( $selected_merchant_id, (int) $merchant_option['id'] ); ?> <?php disabled( ! $merchant_enabled ); ?>>
													<?php echo esc_html( $merchant_label . ( $merchant_enabled ? '' : ' · нет доступа' ) ); ?>
												</option>
											<?php endforeach; ?>
										<?php endif; ?>
									</select>
								</div>
							</div>
							<div class="col-lg-4">
								<p class="hint-text m-b-0">Профиль канала, тарифы, подписчики и платежи загружаются для выбранного мерчанта.</p>
							</div>
						</div>
					</div>
				</div>

				<ul class="nav nav-tabs nav-tabs-simple m-b-0" role="tablist" data-init-reponsive-tabs="dropdownfx">
					<li class="nav-item"><a class="active" data-bs-toggle="tab" data-bs-target="#tab-tgch-overview" href="#tab-tgch-overview">Обзор</a></li>
					<li class="nav-item"><a data-bs-toggle="tab" data-bs-target="#tab-tgch-channel" href="#tab-tgch-channel">Канал</a></li>
					<li class="nav-item"><a data-bs-toggle="tab" data-bs-target="#tab-tgch-tariffs" href="#tab-tgch-tariffs">Тарифы</a></li>
					<li class="nav-item"><a data-bs-toggle="tab" data-bs-target="#tab-tgch-subscribers" href="#tab-tgch-subscribers">Подписчики</a></li>
					<li class="nav-item"><a data-bs-toggle="tab" data-bs-target="#tab-tgch-payments" href="#tab-tgch-payments">Платежи</a></li>
					<li class="nav-item"><a data-bs-toggle="tab" data-bs-target="#tab-tgch-settings" href="#tab-tgch-settings">Настройки</a></li>
					<li class="nav-item"><a data-bs-toggle="tab" data-bs-target="#tab-tgch-texts" href="#tab-tgch-texts">Тексты</a></li>
					<li class="nav-item"><a data-bs-toggle="tab" data-bs-target="#tab-tgch-identity" href="#tab-tgch-identity">Оформление</a></li>
				</ul>

				<div class="tab-content" style="padding:0;overflow:visible">
					<div class="tab-pane active" id="tab-tgch-overview">
						<div class="card card-default m-t-20">
							<div class="card-header"><div class="card-title">Readiness</div></div>
							<div class="card-body">
								<div id="tgch-readiness-host">
									<?php if ( empty( $readiness['issues'] ) ) : ?>
										<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
											<div>
												<div class="hint-text fs-12 text-uppercase m-b-5">Статус модуля</div>
												<h5 class="m-b-5">Модуль готов к публичному flow</h5>
												<p class="hint-text m-b-0">Тарифы, bot, канал и fintech настроены.</p>
											</div>
											<button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#tgch-readiness-modal">Открыть детали</button>
										</div>
									<?php else : ?>
										<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
											<div>
												<div class="hint-text fs-12 text-uppercase m-b-5">Статус модуля</div>
												<h5 class="m-b-5">Публичный flow заблокирован</h5>
												<p class="hint-text m-b-0">Нужно закрыть блокирующие настройки: <?php echo (int) count( $readiness['issues'] ); ?>.</p>
											</div>
											<button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#tgch-readiness-modal">Показать проблемы</button>
										</div>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</div>

					<div class="tab-pane" id="tab-tgch-channel">
						<div class="card card-default m-t-20">
							<div class="card-header"><div class="card-title">Канал</div></div>
							<div class="card-body">
								<form id="tgch-channel-form">
									<input type="hidden" name="merchant_id" class="tgch-merchant-id-field" value="<?php echo (int) $selected_merchant_id; ?>">
									<div class="row">
										<div class="col-md-6">
											<div class="form-group form-group-default">
												<label>Название</label>
												<input type="text" class="form-control" id="tgch-channel-title" name="title" value="<?php echo esc_attr( $channel ? (string) $channel->title : 'Telegram-канал' ); ?>">
											</div>
										</div>
										<div class="col-md-3">
											<div class="form-group form-group-default">
												<label>Channel ID</label>
												<input type="text" class="form-control" id="tgch-channel-telegram-id" name="telegram_channel_id" placeholder="-100..." value="<?php echo esc_attr( $channel ? (string) $channel->telegram_channel_id : '' ); ?>">
											</div>
										</div>
										<div class="col-md-3">
											<div class="form-group form-group-default">
												<label>Username</label>
												<input type="text" class="form-control" id="tgch-channel-username" name="telegram_channel_username" placeholder="channel" value="<?php echo esc_attr( $channel ? (string) $channel->telegram_channel_username : '' ); ?>">
											</div>
										</div>
									</div>
									<div class="row">
										<div class="col-md-4">
											<div class="form-group form-group-default form-group-default-select2">
												<label>Статус</label>
												<select class="full-width" name="status" id="tgch-channel-status" data-init-plugin="select2" data-select2-hide-search="1">
													<?php foreach ( [ 'draft' => 'draft', 'active' => 'active', 'disabled' => 'disabled' ] as $value => $label ) : ?>
														<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $channel ? (string) $channel->status : 'draft', $value ); ?>><?php echo esc_html( $label ); ?></option>
													<?php endforeach; ?>
												</select>
											</div>
										</div>
										<div class="col-md-8 d-flex align-items-end gap-2">
											<button type="button" class="btn btn-primary tgch-save tgch-profile-action" data-form="#tgch-channel-form" data-action="me_telegram_channels_save_channel" <?php disabled( ! $can_settings || $selected_merchant_id <= 0 ); ?>>Сохранить канал</button>
											<button type="button" class="btn btn-success tgch-profile-action" id="tgch-connect-channel-telegram" <?php disabled( ! $can_settings || $selected_merchant_id <= 0 ); ?>>Подключить через Telegram</button>
											<button type="button" class="btn btn-default tgch-profile-action" id="tgch-check-admin" <?php disabled( ! $can_settings || $selected_merchant_id <= 0 ); ?>>Проверить права бота</button>
										</div>
									</div>
									<p class="hint-text m-t-10 m-b-0">Subscription bot должен быть администратором закрытого канала с правами на invite-ссылки и удаление участников. Кнопка «Подключить через Telegram» откроет бота и попросит выбрать канал нативным Telegram selector.</p>
								</form>
							</div>
						</div>
					</div>

					<div class="tab-pane" id="tab-tgch-tariffs">
						<div class="card card-default m-t-20">
							<div class="card-header"><div class="card-title">Тарифы</div></div>
							<div class="card-body">
								<form id="tgch-tariffs-form">
									<input type="hidden" name="merchant_id" class="tgch-merchant-id-field" value="<?php echo (int) $selected_merchant_id; ?>">
									<div class="table-responsive">
										<table class="table table-hover">
											<thead>
												<tr>
													<th>Тариф</th>
													<th>Дней</th>
													<th>Цена</th>
													<th>Валюта</th>
													<th>Активен</th>
												</tr>
											</thead>
											<tbody id="tgch-tariffs-tbody">
												<?php foreach ( $tariffs as $tariff ) : ?>
													<?php
													$tariff_currency_options = crm_telegram_channels_price_currency_options( $company_id, $selected_merchant_id, (string) $tariff->price_currency );
													$current_tariff_currency = strtoupper( trim( (string) $tariff->price_currency ) );
													if ( ! in_array( $current_tariff_currency, $tariff_currency_options, true ) && ! empty( $tariff_currency_options ) ) {
														$current_tariff_currency = (string) $tariff_currency_options[0];
													}
													?>
													<tr>
														<td class="v-align-middle">
															<strong><?php echo esc_html( (string) $tariff->title ); ?></strong>
															<div class="hint-text fs-12"><?php echo esc_html( (string) $tariff->code ); ?></div>
														</td>
														<td class="v-align-middle"><?php echo (int) $tariff->duration_days; ?></td>
														<td class="v-align-middle">
															<input type="number" step="0.01" min="0" class="form-control" name="tariffs[<?php echo esc_attr( (string) $tariff->code ); ?>][price_amount]" value="<?php echo esc_attr( (string) round( (float) $tariff->price_amount, 2 ) ); ?>">
														</td>
														<td class="v-align-middle">
															<select class="full-width" name="tariffs[<?php echo esc_attr( (string) $tariff->code ); ?>][price_currency]" data-init-plugin="select2" data-select2-hide-search="1">
																<?php foreach ( $tariff_currency_options as $currency_option ) : ?>
																	<option value="<?php echo esc_attr( $currency_option ); ?>" <?php selected( $current_tariff_currency, $currency_option ); ?>><?php echo esc_html( $currency_option ); ?></option>
																<?php endforeach; ?>
															</select>
														</td>
														<td class="v-align-middle">
															<div class="form-check complete">
																<input type="checkbox" id="tgch-tariff-<?php echo esc_attr( (string) $tariff->code ); ?>" name="tariffs[<?php echo esc_attr( (string) $tariff->code ); ?>][active]" value="1" <?php checked( (string) $tariff->status, 'active' ); ?>>
																<label for="tgch-tariff-<?php echo esc_attr( (string) $tariff->code ); ?>">active</label>
															</div>
														</td>
													</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
									</div>
									<button type="button" class="btn btn-primary tgch-save tgch-profile-action" data-form="#tgch-tariffs-form" data-action="me_telegram_channels_save_tariffs" <?php disabled( ! $can_tariffs || $selected_merchant_id <= 0 ); ?>>Сохранить тарифы</button>
								</form>
							</div>
						</div>
					</div>

					<div class="tab-pane" id="tab-tgch-subscribers">
						<div class="card card-default m-t-20">
							<div class="card-header"><div class="card-title">Подписчики</div></div>
							<div class="card-body table-responsive">
								<table class="table table-hover">
									<thead><tr><th>Telegram</th><th>Тариф</th><th>До</th><th>Статус</th><th></th></tr></thead>
									<tbody id="tgch-subscribers-tbody">
										<?php if ( empty( $subscribers ) ) : ?>
											<tr><td colspan="5" class="text-center hint-text p-t-25 p-b-25">Подписчиков пока нет.</td></tr>
										<?php else : ?>
											<?php foreach ( $subscribers as $subscriber ) : ?>
												<tr>
													<td>
														<strong><?php echo esc_html( $subscriber->username ? '@' . (string) $subscriber->username : (string) $subscriber->telegram_user_id ); ?></strong>
														<div class="hint-text fs-12"><?php echo esc_html( trim( (string) $subscriber->first_name . ' ' . (string) $subscriber->last_name ) ); ?></div>
													</td>
													<td><?php echo esc_html( (string) ( $subscriber->tariff_title ?? '—' ) ); ?></td>
													<td><?php echo esc_html( crm_format_dt( (string) $subscriber->subscription_until, $company_id ) ?: '—' ); ?></td>
													<td><span class="badge badge-<?php echo (string) $subscriber->status === 'active' ? 'success' : 'secondary'; ?>"><?php echo esc_html( (string) $subscriber->status ); ?></span></td>
													<td class="text-right">
														<button type="button" class="btn btn-default btn-xs tgch-reissue-invite" data-id="<?php echo (int) $subscriber->id; ?>" <?php disabled( ! $can_manage_subscribers || (string) $subscriber->status !== 'active' ); ?>>Invite</button>
													</td>
												</tr>
											<?php endforeach; ?>
										<?php endif; ?>
									</tbody>
								</table>
							</div>
						</div>
					</div>

					<div class="tab-pane" id="tab-tgch-payments">
						<div class="card card-default m-t-20">
							<div class="card-header"><div class="card-title">Платежи подписок</div></div>
							<div class="card-body table-responsive">
								<table class="table table-hover">
									<thead><tr><th>Order</th><th>Subscriber</th><th>Тариф</th><th>Сумма</th><th>Paid</th></tr></thead>
									<tbody id="tgch-payments-tbody">
										<?php if ( empty( $payments ) ) : ?>
											<tr><td colspan="5" class="text-center hint-text p-t-25 p-b-25">Платежей подписок пока нет.</td></tr>
										<?php else : ?>
											<?php foreach ( $payments as $payment ) : ?>
												<tr>
													<td><code>#<?php echo (int) $payment->payment_order_id; ?></code><div class="hint-text fs-12"><?php echo esc_html( (string) $payment->order_status ); ?></div></td>
													<td><?php echo esc_html( $payment->subscriber_username ? '@' . (string) $payment->subscriber_username : '—' ); ?></td>
													<td><?php echo esc_html( (string) $payment->tariff_title ); ?></td>
													<td><?php echo esc_html( rtrim( rtrim( number_format( (float) $payment->amount, 2, '.', '' ), '0' ), '.' ) . ' ' . (string) $payment->currency ); ?></td>
													<td><?php echo esc_html( crm_format_dt( (string) $payment->paid_at, $company_id ) ?: '—' ); ?></td>
												</tr>
											<?php endforeach; ?>
										<?php endif; ?>
									</tbody>
								</table>
							</div>
						</div>
					</div>

					<div class="tab-pane" id="tab-tgch-settings">
						<div class="card card-default m-t-20">
							<div class="card-header"><div class="card-title">Subscription bot</div></div>
							<div class="card-body">
								<form id="tgch-settings-form">
									<input type="hidden" name="merchant_id" class="tgch-merchant-id-field" value="<?php echo (int) $selected_merchant_id; ?>">
									<div class="row">
										<div class="col-md-6">
											<div class="form-group form-group-default disabled">
												<label>Bot username</label>
												<input
													type="text"
													class="form-control"
													id="tgch-bot-username-display"
													value="<?php echo esc_attr( ! empty( $settings['bot_username'] ) ? '@' . (string) $settings['bot_username'] : '' ); ?>"
													placeholder="Определится после сохранения token."
													readonly>
											</div>
										</div>
										<div class="col-md-6">
											<div class="form-group form-group-default">
												<label>Bot token</label>
												<input
													type="password"
													class="form-control"
													id="tgch-bot-token"
													name="telegram_subscription_bot_token"
													value=""
													placeholder="<?php echo esc_attr( ! empty( $settings['token_set'] ) ? 'Сохранён: ' . (string) ( $settings['bot_token_masked'] ?? '' ) . '. Для замены вставьте новый token.' : 'Вставьте token из BotFather' ); ?>"
													data-token-set="<?php echo ! empty( $settings['token_set'] ) ? '1' : '0'; ?>">
											</div>
										</div>
									</div>
									<div class="row">
										<div class="col-md-4">
											<div class="form-group form-group-default">
												<label>Дней до напоминания</label>
												<input type="number" class="form-control" min="1" max="30" id="tgch-reminder-days" name="telegram_channels_reminder_days" value="<?php echo esc_attr( $settings['reminder_days'] ); ?>">
											</div>
										</div>
										<div class="col-md-4">
											<div class="form-group form-group-default">
												<label>Срок invite-ссылки, часов</label>
												<input type="number" class="form-control" min="1" max="168" id="tgch-invite-ttl-hours" name="telegram_channels_invite_ttl_hours" value="<?php echo esc_attr( $settings['invite_ttl_hours'] ); ?>">
											</div>
										</div>
										<div class="col-md-4">
											<div class="form-group form-group-default">
												<label>Напоминания подписчикам</label>
												<div class="d-flex align-items-center justify-content-between">
													<div>
														<div id="tgch-reminders-state"><?php echo ! empty( $settings['reminders_enabled'] ) ? 'Включены' : 'Выключены'; ?></div>
														<span class="help">Предупреждать клиента до окончания подписки.</span>
													</div>
													<div class="form-check form-check-inline switch complete m-0">
														<input type="checkbox" id="tgch-reminders-enabled" name="telegram_channels_reminders_enabled" value="1" <?php checked( $settings['reminders_enabled'] ); ?> aria-label="Включить напоминания подписчикам">
														<label for="tgch-reminders-enabled"></label>
													</div>
												</div>
											</div>
										</div>
									</div>
									<div class="row">
										<div class="col-md-9">
											<div class="form-group form-group-default">
												<label>Callback URL</label>
												<input type="text" class="form-control" id="tgch-callback-url" value="<?php echo esc_attr( (string) ( $telegram_status['callback_url'] ?? '' ) ); ?>" readonly>
											</div>
										</div>
										<div class="col-md-3">
											<div class="form-group form-group-default">
												<label>Webhook</label>
												<div class="p-t-5" id="tgch-webhook-status">
													<?php echo ! empty( $telegram_status['webhook_ready'] ) ? '<span class="badge badge-success">connected</span>' : '<span class="badge badge-warning">not connected</span>'; ?>
												</div>
											</div>
										</div>
									</div>
									<div class="d-flex gap-2">
										<button type="button" class="btn btn-primary tgch-save" data-form="#tgch-settings-form" data-action="me_telegram_channels_save_settings" <?php disabled( ! $can_settings || $selected_merchant_id <= 0 ); ?>>Сохранить настройки</button>
										<button type="button" class="btn btn-default" id="tgch-connect-webhook" <?php disabled( ! $can_settings || $selected_merchant_id <= 0 ); ?>>Подключить callback</button>
									</div>
								</form>
							</div>
						</div>
					</div>

					<div class="tab-pane" id="tab-tgch-texts">
						<div class="card card-default m-t-20">
							<div class="card-header"><div class="card-title">Тексты subscription bot</div></div>
							<div class="card-body">
								<form id="tgch-texts-form">
									<input type="hidden" name="merchant_id" class="tgch-merchant-id-field" value="<?php echo (int) $selected_merchant_id; ?>">
									<div class="row">
										<?php foreach ( crm_telegram_channels_default_texts() as $key => $default ) : ?>
											<div class="col-md-6">
												<div class="form-group form-group-default">
													<label><?php echo esc_html( $key ); ?></label>
													<textarea class="form-control" rows="2" name="texts[<?php echo esc_attr( $key ); ?>]"><?php echo esc_textarea( (string) ( $texts[ $key ] ?? $default ) ); ?></textarea>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
									<button type="button" class="btn btn-primary tgch-save" data-form="#tgch-texts-form" data-action="me_telegram_channels_save_texts" <?php disabled( ! $can_settings || $selected_merchant_id <= 0 ); ?>>Сохранить тексты</button>
								</form>
							</div>
						</div>
					</div>

					<div class="tab-pane" id="tab-tgch-identity">
						<div class="card card-default m-t-20">
							<div class="card-header">
								<div class="card-title">Оформление subscription bot</div>
							</div>
							<div class="card-body">
								<form id="tgch-identity-form" enctype="multipart/form-data">
									<input type="hidden" name="merchant_id" class="tgch-merchant-id-field" value="<?php echo (int) $selected_merchant_id; ?>">
									<div id="tgch-identity-status" class="m-b-15">
										<?php if ( ! empty( $identity['last_error'] ) ) : ?>
											<div class="alert alert-warning bordered m-b-0">
												<strong>Последняя ошибка применения:</strong><br>
												<?php echo esc_html( (string) $identity['last_error'] ); ?>
											</div>
										<?php elseif ( ! empty( $identity['applied_at'] ) ) : ?>
											<div class="alert alert-success bordered m-b-0">
												<strong>Оформление применено.</strong><br>
												Последнее применение: <?php echo esc_html( crm_format_dt( (string) $identity['applied_at'], $company_id ) ?: (string) $identity['applied_at'] ); ?>
											</div>
										<?php else : ?>
											<div class="alert alert-info bordered m-b-0">
												<strong>Оформление ещё не применялось.</strong><br>
												Заполните поля и отправьте их в Telegram API выбранного мерчанта.
											</div>
										<?php endif; ?>
									</div>

									<div class="row">
										<div class="col-md-8">
											<div class="form-group form-group-default">
												<label>Название бота</label>
												<input type="text" class="form-control" id="tgch-identity-name" name="identity_name" maxlength="64" value="<?php echo esc_attr( (string) ( $identity['name'] ?? '' ) ); ?>">
											</div>
										</div>
										<div class="col-md-4">
											<div class="form-group form-group-default form-group-default-select2">
												<label>Язык</label>
												<select class="full-width" id="tgch-identity-language" name="identity_language_code" data-init-plugin="select2" data-select2-hide-search="1">
													<?php foreach ( $identity_language_options as $value => $label ) : ?>
														<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (string) ( $identity['language_code'] ?? '' ), (string) $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
													<?php endforeach; ?>
												</select>
											</div>
										</div>
									</div>

									<div class="row">
										<div class="col-md-6">
											<div class="form-group form-group-default">
												<label>Короткое описание (About)</label>
												<textarea class="form-control" rows="2" id="tgch-identity-short-description" name="identity_short_description" maxlength="120"><?php echo esc_textarea( (string) ( $identity['short_description'] ?? '' ) ); ?></textarea>
											</div>
										</div>
										<div class="col-md-6">
											<div class="form-group form-group-default">
												<label>Аватар бота</label>
												<input type="file" class="form-control" id="tgch-identity-photo" name="identity_photo" accept="image/jpeg,image/png,image/webp">
											</div>
											<p class="hint-text m-t-5 m-b-0">Применяется автоматически через Telegram Bot API.</p>
										</div>
									</div>

									<div class="row">
										<div class="col-md-8">
											<div class="form-group form-group-default">
												<label>Полное описание (Description)</label>
												<textarea class="form-control" rows="4" id="tgch-identity-description" name="identity_description" maxlength="512"><?php echo esc_textarea( (string) ( $identity['description'] ?? '' ) ); ?></textarea>
											</div>
										</div>
										<div class="col-md-4">
											<div class="form-group form-group-default">
												<label>Промо-картинка профиля</label>
												<input type="file" class="form-control" id="tgch-identity-promo-image" name="identity_promo_image" accept="image/jpeg,image/png,image/webp">
											</div>
											<p class="hint-text m-t-5 m-b-5">Сохраняется как файл-референс. Устанавливается вручную через @BotFather.</p>
											<div id="tgch-identity-promo-status">
												<?php if ( ! empty( $identity['promo_image_url'] ) ) : ?>
													<a href="<?php echo esc_url( (string) $identity['promo_image_url'] ); ?>" target="_blank" rel="noopener">Открыть сохранённую картинку</a>
													<?php if ( ! empty( $identity['promo_image_uploaded_at'] ) ) : ?>
														<div class="hint-text m-t-5">Загружено: <?php echo esc_html( crm_format_dt( (string) $identity['promo_image_uploaded_at'], $company_id ) ?: (string) $identity['promo_image_uploaded_at'] ); ?></div>
													<?php endif; ?>
												<?php else : ?>
													<span class="hint-text">Файл ещё не загружен.</span>
												<?php endif; ?>
											</div>
										</div>
									</div>

									<div class="row">
										<div class="col-md-4">
											<div class="form-group form-group-default">
												<label>Права администратора</label>
												<div class="form-check complete m-t-5">
													<input type="checkbox" id="tgch-identity-default-admin-rights" name="identity_default_admin_rights" value="1" <?php checked( ! empty( $identity['default_admin_rights'] ) ); ?>>
													<label for="tgch-identity-default-admin-rights">рекомендованные права</label>
												</div>
												<p class="hint-text m-t-10 m-b-0">Эти права будут предложены Telegram при добавлении бота администратором канала.</p>
											</div>
										</div>
									</div>

									<button type="button" class="btn btn-primary" id="tgch-apply-identity" <?php disabled( ! $can_settings || $selected_merchant_id <= 0 ); ?>>Применить оформление</button>
								</form>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<div class="modal fade stick-up" id="tgch-readiness-modal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header clearfix text-left">
				<button aria-label="Закрыть" type="button" class="close" data-bs-dismiss="modal" aria-hidden="true">
					<i class="pg-icon">close</i>
				</button>
				<h5 class="modal-title">Статус <span class="semi-bold">Telegram-каналов</span></h5>
			</div>
			<div class="modal-body">
				<?php if ( empty( $readiness['issues'] ) ) : ?>
					<p class="no-margin">Модуль готов к публичному flow: тарифы, bot, канал и fintech настроены.</p>
				<?php else : ?>
					<p class="m-b-10">Публичный flow заблокирован. Нужно исправить:</p>
					<ul class="m-b-0 p-l-20">
						<?php foreach ( $readiness['issues'] as $issue ) : ?>
							<?php $issue_id = (string) ( $issue['id'] ?? '' ); ?>
							<li>
								<button type="button" class="btn btn-link p-0 tgch-readiness-jump" data-issue-id="<?php echo esc_attr( $issue_id ); ?>">
									<?php echo esc_html( (string) ( $issue['label'] ?? $issue_id ) ); ?>
								</button>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
			<div class="modal-footer">
				<button aria-label="Закрыть" type="button" class="btn btn-default" data-bs-dismiss="modal">Закрыть</button>
			</div>
		</div>
	</div>
</div>

<div class="modal fade stick-up" id="tgch-system-modal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-sm" role="document">
		<div class="modal-content">
			<div class="modal-header clearfix text-left">
				<button aria-label="Закрыть" type="button" class="close" data-bs-dismiss="modal" aria-hidden="true">
					<i class="pg-icon">close</i>
				</button>
				<h5 class="modal-title" id="tgch-system-modal-title">Сообщение</h5>
			</div>
			<div class="modal-body">
				<p class="no-margin" id="tgch-system-modal-message"></p>
			</div>
			<div class="modal-footer">
				<button aria-label="Закрыть" type="button" class="btn btn-primary pull-left inline" id="tgch-system-modal-ok" data-bs-dismiss="modal">Понятно</button>
			</div>
		</div>
	</div>
</div>

<?php
add_action(
	'wp_footer',
	function () use ( $nonce, $selected_merchant_id, $can_settings, $can_tariffs, $can_manage_subscribers ) {
		?>
<script>
(function ($) {
	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCE = '<?php echo esc_js( $nonce ); ?>';
	var selectedMerchantId = <?php echo (int) $selected_merchant_id; ?>;
	var canSettings = <?php echo $can_settings ? 'true' : 'false'; ?>;
	var canTariffs = <?php echo $can_tariffs ? 'true' : 'false'; ?>;
	var canManageSubscribers = <?php echo $can_manage_subscribers ? 'true' : 'false'; ?>;
	var botSettingsDirty = false;
	var systemModal = null;

	function showSystemModal(message, type) {
		var title = 'Сообщение';
		var buttonClass = 'btn-primary';

		if (type === 'danger') {
			title = 'Ошибка';
			buttonClass = 'btn-danger';
		} else if (type === 'warning') {
			title = 'Внимание';
			buttonClass = 'btn-warning';
		} else if (type === 'success') {
			title = 'Готово';
			buttonClass = 'btn-success';
		}

		$('#tgch-system-modal-title').text(title);
		$('#tgch-system-modal-message').text(message || '');
		$('#tgch-system-modal-ok')
			.removeClass('btn-primary btn-danger btn-warning btn-success')
			.addClass(buttonClass);

		if (window.bootstrap && bootstrap.Modal) {
			if (!systemModal) {
				systemModal = new bootstrap.Modal(document.getElementById('tgch-system-modal'));
			}
			systemModal.show();
			return;
		}

		if ($.fn.modal) {
			$('#tgch-system-modal').modal('show');
		}
	}

	function showToast(message, type) {
		if (type === 'danger' || type === 'warning') {
			showSystemModal(message, type);
			return;
		}
		if (window.pgNotification) {
			$('body').pgNotification({
				style: 'bar',
				message: message || '',
				position: 'top-right',
				timeout: 3500,
				type: type || 'info'
			}).show();
			return;
		}
		showSystemModal(message, type);
	}

	function escapeHtml(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function clearFormErrors($form) {
		$form.find('.has-error').removeClass('has-error');
	}

	function markFormFields($form, fields) {
		fields = Array.isArray(fields) ? fields : [];
		fields.forEach(function (fieldName) {
			$form.find('[name="' + fieldName + '"]').closest('.form-group').addClass('has-error');
		});
	}

	function savedTokenIsSet() {
		var $token = $('#tgch-bot-token');
		return $token.data('token-set') === true || String($token.attr('data-token-set') || '') === '1';
	}

	function syncActionButtons() {
		var hasMerchant = selectedMerchantId > 0;
		var canEditSettings = canSettings && hasMerchant;
		$('[data-action="me_telegram_channels_save_channel"], #tgch-connect-channel-telegram, #tgch-check-admin').prop('disabled', !canEditSettings);
		$('[data-action="me_telegram_channels_save_settings"], [data-action="me_telegram_channels_save_texts"]').prop('disabled', !canEditSettings);
		$('[data-action="me_telegram_channels_save_tariffs"]').prop('disabled', !(canTariffs && hasMerchant));
		$('#tgch-connect-webhook, #tgch-apply-identity')
			.prop('disabled', !canEditSettings || botSettingsDirty)
			.attr('title', botSettingsDirty ? 'Сначала сохраните Bot token.' : '');
	}

	function setBotSettingsDirty(dirty) {
		botSettingsDirty = !!dirty;
		syncActionButtons();
	}

	function validateSettingsForm() {
		var $form = $('#tgch-settings-form');
		var token = $.trim($('#tgch-bot-token').val() || '');
		var tokenSet = savedTokenIsSet();
		var missing = [];
		var fields = [];

		clearFormErrors($form);
		if (!token && !tokenSet) {
			missing.push('Bot token');
			fields.push('telegram_subscription_bot_token');
		} else if (token && !/^\d{5,}:[A-Za-z0-9_-]{20,}$/.test(token)) {
			fields.push('telegram_subscription_bot_token');
			markFormFields($form, fields);
			showToast('Bot token выглядит некорректно. Вставьте token из BotFather полностью.', 'warning');
			return false;
		}

		if (missing.length) {
			markFormFields($form, fields);
			showToast('Заполните обязательные поля subscription bot: ' + missing.join(', ') + '.', 'warning');
			return false;
		}

		return true;
	}

	function initSelect2($scope) {
		if (!$.fn.select2) {
			return;
		}
		$scope.find('[data-init-plugin="select2"]').each(function () {
			var $select = $(this);
			if ($select.data('select2')) {
				$select.select2('destroy');
			}
			$select.select2({
				minimumResultsForSearch: $select.data('select2-hide-search') ? Infinity : 0,
				width: '100%'
			});
		});
	}

	function setMerchantId(merchantId) {
		selectedMerchantId = parseInt(merchantId, 10) || 0;
		$('.tgch-merchant-id-field').val(selectedMerchantId || '');
		syncActionButtons();
	}

	function readinessTarget(issueId) {
		issueId = String(issueId || '');
		if (issueId.indexOf('tariff_') === 0 || issueId === 'active_tariff' || issueId === 'merchant_payment_mode') {
			return { tab: '#tab-tgch-tariffs' };
		}
		if (issueId === 'channel_id' || issueId === 'channel_row' || issueId === 'bot_admin') {
			return { tab: '#tab-tgch-channel', focus: issueId === 'bot_admin' ? '#tgch-check-admin' : '#tgch-channel-telegram-id' };
		}
		if (issueId === 'subscription_bot_token') {
			return { tab: '#tab-tgch-settings', focus: '#tgch-bot-token' };
		}
		if (issueId === 'subscription_bot_username') {
			return { tab: '#tab-tgch-settings', focus: '#tgch-bot-token' };
		}
		if (issueId === 'subscription_webhook') {
			return { tab: '#tab-tgch-settings', focus: '#tgch-connect-webhook' };
		}
		if (issueId === 'texts_json') {
			return { tab: '#tab-tgch-texts' };
		}
		if (issueId === 'merchant_id' || issueId === 'merchant_access') {
			return { tab: '#tab-tgch-overview', focus: '#tgch-merchant-select' };
		}
		return { tab: '#tab-tgch-overview' };
	}

	function showTab(tabSelector) {
		var $tab = $('[data-bs-target="' + tabSelector + '"]');
		if (!$tab.length) {
			return;
		}
		if (window.bootstrap && bootstrap.Tab) {
			bootstrap.Tab.getOrCreateInstance($tab[0]).show();
			return;
		}
		if ($.fn.tab) {
			$tab.tab('show');
		}
	}

	function jumpToReadinessIssue(issueId) {
		var target = readinessTarget(issueId);
		if (window.bootstrap && bootstrap.Modal) {
			var modal = bootstrap.Modal.getInstance(document.getElementById('tgch-readiness-modal'));
			if (modal) {
				modal.hide();
			}
		} else if ($.fn.modal) {
			$('#tgch-readiness-modal').modal('hide');
		}
		showTab(target.tab);
		if (target.focus) {
			setTimeout(function () {
				$(target.focus).trigger('focus');
			}, 180);
		}
	}

	function renderReadiness(readiness) {
		readiness = readiness || {};
		var issues = Array.isArray(readiness.issues) ? readiness.issues : [];
		var ready = !!readiness.is_ready;
		$('#tgch-readiness-badge')
			.removeClass('badge-success badge-warning')
			.addClass(ready ? 'badge-success' : 'badge-warning')
			.text(ready ? 'Готов к публичному flow' : 'Требует настройки');

		if (ready) {
			$('#tgch-readiness-host').html(
				'<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">' +
					'<div><div class="hint-text fs-12 text-uppercase m-b-5">Статус модуля</div>' +
					'<h5 class="m-b-5">Модуль готов к публичному flow</h5>' +
					'<p class="hint-text m-b-0">Тарифы, bot, канал и fintech настроены для выбранного мерчанта.</p></div>' +
					'<button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#tgch-readiness-modal">Открыть детали</button>' +
				'</div>'
			);
			$('#tgch-readiness-modal .modal-body').html('<p class="no-margin">Модуль готов к публичному flow: тарифы, bot, канал и fintech настроены для выбранного мерчанта.</p>');
			return;
		}

		var list = issues.map(function (issue) {
			var issueId = String(issue.id || '');
			return '<li><button type="button" class="btn btn-link p-0 tgch-readiness-jump" data-issue-id="' + escapeHtml(issueId) + '">' + escapeHtml(issue.label || issueId) + '</button></li>';
		}).join('');
		$('#tgch-readiness-host').html(
			'<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">' +
				'<div><div class="hint-text fs-12 text-uppercase m-b-5">Статус модуля</div>' +
				'<h5 class="m-b-5">Публичный flow заблокирован</h5>' +
				'<p class="hint-text m-b-0">Нужно закрыть блокирующие настройки: ' + issues.length + '.</p></div>' +
				'<button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#tgch-readiness-modal">Показать проблемы</button>' +
			'</div>'
		);
		$('#tgch-readiness-modal .modal-body').html(
			'<p class="m-b-10">Публичный flow заблокирован. Нужно исправить:</p>' +
			'<ul class="m-b-0 p-l-20">' + list + '</ul>'
		);
	}

	function renderTariffs(tariffs) {
		var rows = '';
		tariffs = Array.isArray(tariffs) ? tariffs : [];
		if (!tariffs.length) {
			rows = '<tr><td colspan="5" class="text-center hint-text p-t-25 p-b-25">Тарифы пока не созданы.</td></tr>';
		} else {
			tariffs.forEach(function (tariff) {
				var code = String(tariff.code || '');
				var options = Array.isArray(tariff.currency_options) && tariff.currency_options.length ? tariff.currency_options : [tariff.price_currency || ''];
				var optionsHtml = options.map(function (currency) {
					currency = String(currency || '');
					return '<option value="' + escapeHtml(currency) + '"' + (currency === String(tariff.price_currency || '') ? ' selected' : '') + '>' + escapeHtml(currency) + '</option>';
				}).join('');
				var inputDisabled = canTariffs ? '' : ' disabled';
				rows += '<tr>' +
					'<td class="v-align-middle"><strong>' + escapeHtml(tariff.title || '') + '</strong><div class="hint-text fs-12">' + escapeHtml(code) + '</div></td>' +
					'<td class="v-align-middle">' + parseInt(tariff.duration_days || 0, 10) + '</td>' +
					'<td class="v-align-middle"><input type="number" step="0.01" min="0" class="form-control" name="tariffs[' + escapeHtml(code) + '][price_amount]" value="' + escapeHtml(tariff.price_amount || '0') + '"' + inputDisabled + '></td>' +
					'<td class="v-align-middle"><select class="full-width" name="tariffs[' + escapeHtml(code) + '][price_currency]" data-init-plugin="select2" data-select2-hide-search="1"' + inputDisabled + '>' + optionsHtml + '</select></td>' +
					'<td class="v-align-middle"><div class="form-check complete"><input type="checkbox" id="tgch-tariff-' + escapeHtml(code) + '" name="tariffs[' + escapeHtml(code) + '][active]" value="1"' + (String(tariff.status || '') === 'active' ? ' checked' : '') + inputDisabled + '><label for="tgch-tariff-' + escapeHtml(code) + '">active</label></div></td>' +
				'</tr>';
			});
		}
		$('#tgch-tariffs-tbody').html(rows);
		initSelect2($('#tgch-tariffs-tbody'));
	}

	function renderSubscribers(subscribers) {
		var rows = '';
		subscribers = Array.isArray(subscribers) ? subscribers : [];
		if (!subscribers.length) {
			rows = '<tr><td colspan="5" class="text-center hint-text p-t-25 p-b-25">Подписчиков пока нет.</td></tr>';
		} else {
			subscribers.forEach(function (subscriber) {
				var active = String(subscriber.status || '') === 'active';
				rows += '<tr>' +
					'<td><strong>' + escapeHtml(subscriber.label || subscriber.telegram_user_id || '') + '</strong><div class="hint-text fs-12">' + escapeHtml(subscriber.full_name || '') + '</div></td>' +
					'<td>' + escapeHtml(subscriber.tariff_title || '—') + '</td>' +
					'<td>' + escapeHtml(subscriber.subscription_until_label || '—') + '</td>' +
					'<td><span class="badge badge-' + (active ? 'success' : 'secondary') + '">' + escapeHtml(subscriber.status || '') + '</span></td>' +
					'<td class="text-right"><button type="button" class="btn btn-default btn-xs tgch-reissue-invite" data-id="' + parseInt(subscriber.id || 0, 10) + '"' + (!(canManageSubscribers && active) ? ' disabled' : '') + '>Invite</button></td>' +
				'</tr>';
			});
		}
		$('#tgch-subscribers-tbody').html(rows);
	}

	function renderPayments(payments) {
		var rows = '';
		payments = Array.isArray(payments) ? payments : [];
		if (!payments.length) {
			rows = '<tr><td colspan="5" class="text-center hint-text p-t-25 p-b-25">Платежей подписок пока нет.</td></tr>';
		} else {
			payments.forEach(function (payment) {
				rows += '<tr>' +
					'<td><code>#' + parseInt(payment.payment_order_id || 0, 10) + '</code><div class="hint-text fs-12">' + escapeHtml(payment.order_status || '') + '</div></td>' +
					'<td>' + escapeHtml(payment.subscriber_label || '—') + '</td>' +
					'<td>' + escapeHtml(payment.tariff_title || '') + '</td>' +
					'<td>' + escapeHtml(payment.amount_label || '') + '</td>' +
					'<td>' + escapeHtml(payment.paid_at_label || '—') + '</td>' +
				'</tr>';
			});
		}
		$('#tgch-payments-tbody').html(rows);
	}

	function renderTexts(texts) {
		texts = texts || {};
		$('#tgch-texts-form textarea[name^="texts["]').each(function () {
			var name = String($(this).attr('name') || '');
			var match = name.match(/^texts\[(.+)\]$/);
			if (match && Object.prototype.hasOwnProperty.call(texts, match[1])) {
				$(this).val(texts[match[1]] || '');
			}
		});
	}

	function setRemindersEnabled(enabled) {
		enabled = !!enabled;
		$('#tgch-reminders-enabled').prop('checked', enabled);
		$('#tgch-reminders-state').text(enabled ? 'Включены' : 'Выключены');
	}

	function renderSubscriptionBot(subscriptionBot) {
		subscriptionBot = subscriptionBot || {};
		var settings = subscriptionBot.settings || {};
		var tokenSet = !!settings.token_set;
		var tokenMasked = settings.bot_token_masked || 'скрыт';
		var botUsername = settings.bot_username || subscriptionBot.bot_username || '';
		$('#tgch-bot-username-display').val(botUsername ? '@' + botUsername : '');
		$('#tgch-bot-token')
			.val('')
			.attr('placeholder', tokenSet ? 'Сохранён: ' + tokenMasked + '. Для замены вставьте новый token.' : 'Вставьте token из BotFather')
			.attr('data-token-set', tokenSet ? '1' : '0')
			.data('token-set', tokenSet);
		$('#tgch-reminder-days').val(settings.reminder_days || '3');
		$('#tgch-invite-ttl-hours').val(settings.invite_ttl_hours || '24');
		setRemindersEnabled(settings.reminders_enabled !== false);
		$('#tgch-callback-url').val(subscriptionBot.callback_url || '');
		$('#tgch-webhook-status').html(
			subscriptionBot.webhook_ready
			? '<span class="badge badge-success">connected</span>'
			: '<span class="badge badge-warning">not connected</span>'
		);
		renderTexts(subscriptionBot.texts || {});
		renderBotIdentity(subscriptionBot.identity || {});
		setBotSettingsDirty(false);
	}

	function setSelectOptions($select, options, selected) {
		if (options && typeof options === 'object') {
			var html = '';
			Object.keys(options).forEach(function (value) {
				html += '<option value="' + escapeHtml(value) + '">' + escapeHtml(options[value]) + '</option>';
			});
			$select.html(html);
		}
		$select.val(selected || '').trigger('change');
	}

	function renderPromoImageStatus(identity) {
		identity = identity || {};
		var url = identity.promo_image_url || '';
		var uploadedAt = identity.promo_image_uploaded_at || '';
		if (url) {
			$('#tgch-identity-promo-status').html(
				'<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener">Открыть сохранённую картинку</a>' +
				(uploadedAt ? '<div class="hint-text m-t-5">Загружено: ' + escapeHtml(uploadedAt) + '</div>' : '')
			);
			return;
		}
		$('#tgch-identity-promo-status').html('<span class="hint-text">Файл ещё не загружен.</span>');
	}

	function renderBotIdentity(identity) {
		identity = identity || {};
		$('#tgch-identity-name').val(identity.name || '');
		$('#tgch-identity-short-description').val(identity.short_description || '');
		$('#tgch-identity-description').val(identity.description || '');
		setSelectOptions($('#tgch-identity-language'), identity.language_options, identity.language_code || '');
		$('#tgch-identity-default-admin-rights').prop('checked', identity.default_admin_rights !== false);
		renderPromoImageStatus(identity);

		if (identity.last_error) {
			$('#tgch-identity-status').html(
				'<div class="alert alert-warning bordered m-b-0">' +
					'<strong>Последняя ошибка применения:</strong><br>' + escapeHtml(identity.last_error) +
				'</div>'
			);
			return;
		}
		if (identity.applied_at) {
			var photoLine = identity.photo_applied_at ? '<div class="m-t-5">Аватар: ' + escapeHtml(identity.photo_applied_at) + '</div>' : '';
			$('#tgch-identity-status').html(
				'<div class="alert alert-success bordered m-b-0">' +
					'<strong>Оформление применено.</strong><br>Последнее применение: ' + escapeHtml(identity.applied_at) + photoLine +
				'</div>'
			);
			return;
		}
		$('#tgch-identity-status').html(
			'<div class="alert alert-info bordered m-b-0">' +
				'<strong>Оформление ещё не применялось.</strong><br>Заполните поля и отправьте их в Telegram API выбранного мерчанта.' +
			'</div>'
		);
	}

	function applyProfile(profile) {
		if (!profile || !profile.merchant) {
			return;
		}
		setMerchantId(profile.merchant.id || 0);
		if (profile.channel) {
			$('#tgch-channel-title').val(profile.channel.title || '');
			$('#tgch-channel-telegram-id').val(profile.channel.telegram_channel_id || '');
			$('#tgch-channel-username').val(profile.channel.telegram_channel_username || '');
			$('#tgch-channel-status').val(profile.channel.status || 'draft').trigger('change');
		}
		renderReadiness(profile.readiness || {});
		renderTariffs(profile.tariffs || []);
		renderSubscribers(profile.subscribers || []);
		renderPayments(profile.payments || []);
		renderSubscriptionBot(profile.subscription_bot || {});
	}

	function loadProfile(merchantId) {
		merchantId = parseInt(merchantId, 10) || 0;
		if (merchantId <= 0) {
			setMerchantId(0);
			return;
		}
		$.post(AJAX_URL, { action: 'me_telegram_channels_load_profile', _nonce: NONCE, merchant_id: merchantId })
			.done(function (res) {
				if (!res || !res.success) {
					showToast((res && res.data && res.data.message) || 'Профиль мерчанта не загружен.', 'danger');
					return;
				}
				applyProfile(res.data.profile || {});
				if (window.history && window.history.replaceState) {
					var url = new URL(window.location.href);
					url.searchParams.set('merchant_id', merchantId);
					window.history.replaceState({}, '', url.toString());
				}
			})
			.fail(function (xhr) {
				showToast((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Ошибка загрузки профиля мерчанта.', 'danger');
			});
	}

	function postForm(action, formSelector, $button) {
		var $form = $(formSelector);
		clearFormErrors($form);
		if (selectedMerchantId <= 0) {
			showToast('Выберите мерчанта.', 'warning');
			return;
		}
		if (action === 'me_telegram_channels_save_settings' && !validateSettingsForm()) {
			return;
		}
		var data = $(formSelector).serializeArray();
		data.push({ name: 'action', value: action });
		data.push({ name: '_nonce', value: NONCE });
		data.push({ name: 'merchant_id', value: selectedMerchantId });
		$button.prop('disabled', true);
		$.post(AJAX_URL, data)
			.done(function (res) {
				if (!res || !res.success) {
					if (res && res.data && res.data.fields) {
						markFormFields($form, res.data.fields);
					}
					showToast((res && res.data && res.data.message) || 'Ошибка сохранения.', 'danger');
					return;
				}
				showToast(res.data.message || 'Сохранено.', 'success');
				if (action === 'me_telegram_channels_save_texts' && res.data.profile) {
					renderTexts((res.data.profile.subscription_bot && res.data.profile.subscription_bot.texts) || {});
					renderReadiness(res.data.profile.readiness || {});
				} else if (res.data.profile) {
					applyProfile(res.data.profile);
				} else if (res.data.readiness) {
					renderReadiness(res.data.readiness);
				} else if (selectedMerchantId > 0) {
					loadProfile(selectedMerchantId);
				}
			})
			.fail(function (xhr) {
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.fields) {
					markFormFields($form, xhr.responseJSON.data.fields);
				}
				showToast((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Ошибка сервера.', 'danger');
			})
			.always(function () {
				syncActionButtons();
			});
	}

	function submitTelegramChannelsForm($form) {
		var formId = $form.attr('id') || '';
		var $button;

		if (!formId) {
			return;
		}
		if (formId === 'tgch-identity-form') {
			$('#tgch-apply-identity').trigger('click');
			return;
		}

		$button = $('.tgch-save[data-form="#' + formId + '"]').first();
		if (!$button.length || $button.prop('disabled')) {
			return;
		}
		postForm($button.data('action'), $button.data('form'), $button);
	}

	$(document).on('submit', 'form[id^="tgch-"]', function (event) {
		event.preventDefault();
		submitTelegramChannelsForm($(this));
	});

	$('.tgch-save').on('click', function (event) {
		event.preventDefault();
		var $button = $(this);
		postForm($button.data('action'), $button.data('form'), $button);
	});

	$('#tgch-bot-token').on('input change', function () {
		setBotSettingsDirty(true);
	});

	$('#tgch-reminders-enabled').on('change', function () {
		setRemindersEnabled(this.checked);
	});

	$(document).on('click', '.tgch-readiness-jump', function (event) {
		event.preventDefault();
		jumpToReadinessIssue($(this).data('issue-id'));
	});

	$('#tgch-apply-identity').on('click', function () {
		var $button = $(this);
		var form = document.getElementById('tgch-identity-form');
		if (!form || selectedMerchantId <= 0) {
			showToast('Выберите мерчанта.', 'warning');
			return;
		}
		if (botSettingsDirty) {
			showToast('Сначала сохраните Bot token, затем применяйте оформление.', 'warning');
			return;
		}

		var data = new FormData(form);
		if (data.set) {
			data.set('action', 'me_telegram_channels_apply_bot_identity');
			data.set('_nonce', NONCE);
			data.set('merchant_id', selectedMerchantId);
		} else {
			data.append('action', 'me_telegram_channels_apply_bot_identity');
			data.append('_nonce', NONCE);
			data.append('merchant_id', selectedMerchantId);
		}

		$button.prop('disabled', true);
		$.ajax({
			url: AJAX_URL,
			type: 'POST',
			data: data,
			processData: false,
			contentType: false
		})
			.done(function (res) {
				if (!res || !res.success) {
					showToast((res && res.data && res.data.message) || 'Оформление не применено.', 'danger');
					if (res && res.data && res.data.profile) {
						applyProfile(res.data.profile);
					}
					return;
				}
				showToast(res.data.message || 'Оформление применено.', 'success');
				$('#tgch-identity-photo').val('');
				$('#tgch-identity-promo-image').val('');
				if (res.data.profile) {
					applyProfile(res.data.profile);
				} else {
					loadProfile(selectedMerchantId);
				}
			})
			.fail(function (xhr) {
				showToast((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Ошибка сервера.', 'danger');
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.profile) {
					applyProfile(xhr.responseJSON.data.profile);
				}
			})
			.always(function () {
				syncActionButtons();
			});
	});

	$('#tgch-merchant-select').on('change', function () {
		loadProfile($(this).val());
	});

	$('#tgch-check-admin').on('click', function () {
		var $button = $(this).prop('disabled', true);
		$.post(AJAX_URL, { action: 'me_telegram_channels_check_admin', _nonce: NONCE, merchant_id: selectedMerchantId })
			.done(function (res) {
				if (!res || !res.success) {
					showToast((res && res.data && res.data.message) || 'Проверка не прошла.', 'danger');
					return;
				}
				showToast(res.data.message || 'Права подтверждены.', 'success');
				if (res.data.profile) {
					applyProfile(res.data.profile);
				} else {
					loadProfile(selectedMerchantId);
				}
			})
			.fail(function (xhr) {
				showToast((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Ошибка сервера.', 'danger');
			})
			.always(function () { syncActionButtons(); });
	});

	$('#tgch-connect-channel-telegram').on('click', function () {
		var $button = $(this).prop('disabled', true);
		var setupWindow = null;

		try {
			setupWindow = window.open('about:blank', '_blank');
			if (setupWindow) {
				setupWindow.opener = null;
				setupWindow.document.title = 'Telegram';
				setupWindow.document.body.innerHTML = '<p style="font-family:Arial,sans-serif;padding:24px">Готовим ссылку подключения Telegram...</p>';
			}
		} catch (e) {
			setupWindow = null;
		}

		$.post(AJAX_URL, { action: 'me_telegram_channels_create_channel_setup', _nonce: NONCE, merchant_id: selectedMerchantId })
			.done(function (res) {
				var setupUrl = res && res.data && res.data.setup_url ? String(res.data.setup_url) : '';
				if (!res || !res.success || !setupUrl) {
					if (setupWindow && !setupWindow.closed) {
						setupWindow.close();
					}
					showToast((res && res.data && res.data.message) || 'Setup-ссылка не создана.', 'danger');
					return;
				}

				if (setupWindow && !setupWindow.closed) {
					setupWindow.location.href = setupUrl;
					showToast('Telegram открыт в новой вкладке. Выберите канал, затем вернитесь на эту страницу.', 'success');
					return;
				}

				showToast('Браузер заблокировал новую вкладку. Разрешите pop-up для сайта и нажмите кнопку ещё раз.', 'warning');
			})
			.fail(function (xhr) {
				if (setupWindow && !setupWindow.closed) {
					setupWindow.close();
				}
				showToast((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Ошибка сервера при создании setup-ссылки.', 'danger');
			})
			.always(function () { syncActionButtons(); });
	});

	$('#tgch-connect-webhook').on('click', function () {
		if (selectedMerchantId <= 0) {
			showToast('Выберите мерчанта.', 'warning');
			return;
		}
		if (botSettingsDirty) {
			showToast('Сначала сохраните Bot token, затем подключайте callback.', 'warning');
			return;
		}
		var $button = $(this).prop('disabled', true);
		$.post(AJAX_URL, { action: 'me_telegram_channels_connect_webhook', _nonce: NONCE, merchant_id: selectedMerchantId })
			.done(function (res) {
				if (!res || !res.success) {
					showToast((res && res.data && res.data.message) || 'Callback не подключён.', 'danger');
					return;
				}
				showToast(res.data.message || 'Callback подключён.', 'success');
				if (res.data.profile) {
					applyProfile(res.data.profile);
				} else if (res.data.readiness) {
					renderReadiness(res.data.readiness);
				}
			})
			.fail(function (xhr) {
				showToast((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Ошибка сервера.', 'danger');
			})
			.always(function () { syncActionButtons(); });
	});

	$(document).on('click', '.tgch-reissue-invite', function () {
		var $button = $(this).prop('disabled', true);
		$.post(AJAX_URL, { action: 'me_telegram_channels_reissue_invite', _nonce: NONCE, merchant_id: selectedMerchantId, subscriber_id: $button.data('id') })
			.done(function (res) {
				if (!res || !res.success) {
					showToast((res && res.data && res.data.message) || 'Invite не создан.', 'danger');
					return;
				}
				showToast(res.data.message || 'Invite отправлен.', 'success');
			})
			.fail(function (xhr) {
				showToast((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Ошибка сервера.', 'danger');
			})
			.always(function () { $button.prop('disabled', false); });
	});

	setMerchantId(selectedMerchantId);
	initSelect2($(document));
}(jQuery));
</script>
		<?php
	},
	99
);
?>

<?php
get_template_part( 'template-parts/quickview' );
get_template_part( 'template-parts/overlay' );
get_footer();

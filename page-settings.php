<?php
/*
Template Name: Settings Page
Slug: settings
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

if ( ! crm_user_has_permission( get_current_user_id(), 'settings.view' ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

// Root (uid=1) имеет org_id = 0 — системный контекст, отдельный от всех компаний.
// Обычный пользователь — org_id его компании.
$org_id   = crm_is_root( get_current_user_id() ) ? 0 : crm_get_current_user_company_id( get_current_user_id() );
$settings = crm_get_all_settings( $org_id );
$telegram_contexts = crm_telegram_bot_context_labels();
$telegram_states   = [];

foreach ( $telegram_contexts as $telegram_context => $telegram_context_label ) {
	$telegram_context = crm_telegram_normalize_bot_context( $telegram_context );

	if ( $org_id > 0 ) {
		$telegram_settings = crm_telegram_collect_settings( $org_id, $telegram_context );
		$telegram_status   = crm_telegram_get_configuration_status( $org_id, $telegram_context, true );
	} else {
		$telegram_settings = [
			'bot_token'    => '',
			'bot_username' => '',
		];
		$telegram_status = [
			'context'              => $telegram_context,
			'context_label'        => $telegram_context_label,
			'is_configured'        => false,
			'webhook_ready'        => false,
			'invite_ready'         => false,
			'operator_ready'       => false,
			'blocked_reason'       => 'Telegram-настройки доступны только в контексте компании.',
			'missing_fields'       => [],
			'callback_url'         => '',
			'legacy_callback_url'  => '',
			'bot_handle'           => '',
			'webhook_connected_at' => '',
			'webhook_last_error'   => '',
			'webhook_lock'         => false,
		];
	}

	$telegram_states[ $telegram_context ] = [
		'label'    => $telegram_context_label,
		'settings' => $telegram_settings,
		'status'   => $telegram_status,
	];
}

// Fintech settings.
// Читаем ТОЛЬКО настройки данной компании. Никакого fallback на другие компании.
// Если настройки не заданы — $_fintech_not_configured = true, форма показывает предупреждение.
$fintech_status          = crm_fintech_get_configuration_status( $org_id );
$_fintech_not_configured = ! empty( $fintech_status['is_configured'] ) ? false : true;
$fintech                 = $fintech_status['settings'];
$fintech_missing_ids     = array_values( array_filter( array_map(
	static fn( $item ) => isset( $item['id'] ) ? (string) $item['id'] : '',
	$fintech_status['missing_fields'] ?? []
) ) );
$fintech_provider_labels       = crm_fintech_provider_labels();
$fintech_allowed_providers     = array_values( $fintech['allowed_providers'] ?? crm_fintech_default_allowed_providers() );
$fintech_active_provider_allowed = in_array( $fintech['active_provider'], $fintech_allowed_providers, true );
$_root_company_context         = null;

if ( ! function_exists( 'me_settings_render_fintech_status_html' ) ) {
	function me_settings_render_fintech_status_html( array $status ): string {
		$provider_label          = (string) ( $status['provider_label'] ?? 'Не выбран' );
		$blocked_reason          = trim( (string) ( $status['blocked_reason'] ?? '' ) );
		$allowed_provider_labels = array_values( array_filter( array_map(
			static fn( $item ) => (string) $item,
			$status['allowed_provider_labels'] ?? []
		) ) );
		$missing_general         = array_values( array_filter( array_map(
			static fn( $item ) => (string) ( $item['label'] ?? '' ),
			$status['missing_general'] ?? []
		) ) );
		$missing_provider        = array_values( array_filter( array_map(
			static fn( $item ) => (string) ( $item['label'] ?? '' ),
			$status['missing_provider'] ?? []
		) ) );

		ob_start();
		if ( ! empty( $status['is_configured'] ) ) :
			?>
			<div class="alert alert-success bordered m-b-15">
				<strong>Платёжный шлюз готов к работе.</strong><br>
				Активный провайдер: <?php echo esc_html( $provider_label ); ?>.
			</div>
			<?php
			else :
				?>
				<div class="alert alert-danger bordered m-b-15">
					<strong>Платёжные ордера сейчас заблокированы.</strong><br>
					<?php if ( $blocked_reason !== '' ) : ?>
						<?php echo esc_html( $blocked_reason ); ?>
					<?php elseif ( ! empty( $status['provider'] ) ) : ?>
						Сейчас выбран провайдер: <?php echo esc_html( $provider_label ); ?>.
					<?php else : ?>
						Сначала выберите активный провайдер в блоке общих настроек.
					<?php endif; ?>
					<?php if ( ! empty( $allowed_provider_labels ) ) : ?>
						<div class="m-t-10">
							<strong>Доступные контуры:</strong> <?php echo esc_html( implode( ', ', $allowed_provider_labels ) ); ?>.
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $missing_general ) || ! empty( $missing_provider ) ) : ?>
						<div class="m-t-10">
							<div class="bold fs-12">Что именно нужно заполнить:</div>
						<ul class="m-b-0 p-l-20">
							<?php if ( ! empty( $missing_general ) ) : ?>
								<li>В блоке «<?php echo esc_html( $status['general_section_label'] ?? 'Общие настройки' ); ?>»: <?php echo esc_html( implode( ', ', $missing_general ) ); ?>.</li>
							<?php endif; ?>
							<?php if ( ! empty( $missing_provider ) ) : ?>
								<li>В блоке «<?php echo esc_html( $status['provider_section_label'] ?? 'Настройки провайдера' ); ?>»: <?php echo esc_html( implode( ', ', $missing_provider ) ); ?>.</li>
							<?php endif; ?>
						</ul>
					</div>
					<div class="m-t-10">После заполнения нажмите «Сохранить» в общем блоке и в блоке активного провайдера.</div>
				<?php endif; ?>
			</div>
			<?php
		endif;

		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'me_settings_render_telegram_status_html' ) ) {
	function me_settings_render_telegram_status_html( array $status ): string {
		$missing_labels = array_values( array_filter( array_map(
			static fn( $item ) => (string) ( $item['label'] ?? '' ),
			$status['missing_fields'] ?? []
		) ) );
		$blocked_reason = trim( (string) ( $status['blocked_reason'] ?? '' ) );
		$bot_handle = trim( (string) ( $status['bot_handle'] ?? '' ) );
		$last_error = trim( (string) ( $status['webhook_last_error'] ?? '' ) );
		$is_operator = (string) ( $status['context'] ?? 'merchant' ) === 'operator';
		$is_ready = $is_operator ? ! empty( $status['operator_ready'] ) : ! empty( $status['invite_ready'] );
		$ready_title = $is_operator ? 'Операторский Telegram-бот готов к работе.' : 'Создание Telegram invite-ссылок доступно.';
		$ready_text = $is_operator
			? 'Операторский контур может принимать команды через отдельный бот.'
			: 'Администраторы компаний могут создавать новые invite-ссылки и QR-коды для подключения мерчантов.';
		$blocked_title = $is_operator ? 'Операторский Telegram-бот сейчас заблокирован.' : 'Создание Telegram invite-ссылок заблокировано.';
		$blocked_default = $is_operator
			? 'Чтобы включить операторский контур, заполните имя и токен бота в этом разделе.'
			: 'Чтобы включить приглашения мерчантов, заполните имя бота и токен бота в этом разделе.';
		$warning_title = $is_operator
			? 'Callback операторского бота не подключён.'
			: 'Callback мерчантского бота не подключён.';

		ob_start();
		if ( $is_ready ) :
			?>
			<div class="alert alert-success bordered m-b-15">
				<strong><?php echo esc_html( $ready_title ); ?></strong><br>
				<?php if ( $bot_handle !== '' ) : ?>
					Бот: <?php echo esc_html( $bot_handle ); ?>.
				<?php endif; ?>
				<?php echo esc_html( $ready_text ); ?>
			</div>
			<?php
		elseif ( ! empty( $status['is_configured'] ) ) :
			?>
			<div class="alert alert-warning bordered m-b-15">
				<strong><?php echo esc_html( $warning_title ); ?></strong><br>
				<?php if ( $blocked_reason !== '' ) : ?>
					<?php echo esc_html( $blocked_reason ); ?>
				<?php else : ?>
					Нажмите «Подключить callback», чтобы зарегистрировать webhook для этой компании.
				<?php endif; ?>
				<?php if ( ! $is_operator ) : ?>
					<div class="m-t-10">Это не статус мерчантов: уже созданные мерчанты и их активность в таблице не меняются. Ограничено только создание новых Telegram invite-ссылок.</div>
				<?php endif; ?>
				<?php if ( $last_error !== '' ) : ?>
					<div class="m-t-10"><strong>Последняя ошибка Telegram API:</strong> <?php echo esc_html( $last_error ); ?></div>
				<?php endif; ?>
			</div>
			<?php
		else :
			?>
			<div class="alert alert-danger bordered m-b-15">
				<strong><?php echo esc_html( $blocked_title ); ?></strong><br>
				<?php echo esc_html( $blocked_reason !== '' ? $blocked_reason : $blocked_default ); ?>
				<?php if ( ! empty( $missing_labels ) ) : ?>
					<div class="m-t-10">
						<div class="bold fs-12">Что нужно исправить:</div>
						<ul class="m-b-0 p-l-20">
							<li><?php echo esc_html( implode( ', ', $missing_labels ) ); ?></li>
						</ul>
					</div>
				<?php endif; ?>
			</div>
			<?php
		endif;

		return (string) ob_get_clean();
	}
}

$current_tz = $settings['timezone'] ?? 'UTC';

$pair       = rates_get_pair( RATES_PAIR_CODE, $org_id );
$coeff      = $pair ? rates_get_coefficient( (int) $pair->id, RATES_PROVIDER_EX24, RATES_PROVIDER_SOURCE ) : 0.05;
$merchant_settings = $org_id > 0 ? crm_merchants_get_settings( $org_id ) : null;

// Снимок состояния всех валютных пар для текущей компании.
$rates_pair_blocks      = [];
$usdt_market_pair_codes = [ 'USDT_THB' ];
if ( function_exists( 'crm_root_available_rate_pairs' ) ) {
	foreach ( crm_root_available_rate_pairs() as $pair_def ) {
		$existing = function_exists( 'rates_get_any_pair' ) ? rates_get_any_pair( $pair_def['code'], $org_id ) : null;

		$coeff_value = null;
		$coeff_type  = 'absolute';

		if ( $existing && (int) $existing->is_active === 1 ) {
			$state = 'active';
		} elseif ( $existing ) {
			$state = 'disabled';
		} else {
			$state = 'not_configured';
		}

		if ( $existing ) {
			$full        = rates_get_coefficient_full( (int) $existing->id, RATES_PROVIDER_EX24, RATES_PROVIDER_SOURCE );
			$coeff_value = (float) $full['value'];
			$coeff_type  = (string) $full['type'];
		}

		$rates_pair_blocks[] = [
			'pair_code'         => $pair_def['code'],
			'pair_title'        => $pair_def['title'],
			'state'             => $state,
			'coefficient'       => $coeff_value,
			'coefficient_type'  => $coeff_type,
			'has_market_source' => in_array( $pair_def['code'], $usdt_market_pair_codes, true ),
			'market_source'     => $existing ? (string) ( $existing->market_source ?? 'bitkub' ) : 'bitkub',
		];
	}
}

$nonce_save      = wp_create_nonce( 'me_settings_save' );
$telegram_statuses_bootstrap = [];
foreach ( array_keys( $telegram_states ) as $telegram_context ) {
	$telegram_statuses_bootstrap[ $telegram_context ] = _me_settings_telegram_status_payload(
		$org_id > 0 ? $org_id : 0,
		$telegram_context,
		true
	);
}
$settings_js_bootstrap = [
	'ajax_url'                  => admin_url( 'admin-ajax.php' ),
	'nonce'                     => $nonce_save,
	'fintech_allowed_providers' => array_values( $fintech_allowed_providers ),
	'fintech_provider_labels'   => $fintech_provider_labels,
	'telegram_status'           => $telegram_statuses_bootstrap['merchant'] ?? [],
	'telegram_statuses'         => $telegram_statuses_bootstrap,
];

get_header();
?>

<!-- BEGIN SIDEBAR-->
<?php get_template_part( 'template-parts/sidebar' ); ?>
<!-- END SIDEBAR -->

<div class="page-container">

	<?php get_template_part( 'template-parts/header-backoffice' ); ?>

	<div class="page-content-wrapper">
		<div class="content">

			<div class="jumbotron" data-pages="parallax">
				<div class="container-fluid container-fixed-lg sm-p-l-0 sm-p-r-0">
					<div class="inner">
						<ol class="breadcrumb">
							<li class="breadcrumb-item"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a></li>
							<li class="breadcrumb-item active">Настройки</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">

				<?php if ( $_root_company_context ) : ?>
				<div class="alert alert-info bordered m-b-20">
					<div class="d-flex align-items-center justify-content-between">
						<div>
							<strong>Настройки компании:</strong>
							<?php echo esc_html( $_root_company_context->name ); ?>
							<span class="hint-text m-l-5 fs-12">(#<?php echo (int) $_root_company_context->id; ?> · <?php echo esc_html( $_root_company_context->code ); ?>)</span>
						</div>
						<a href="<?php echo esc_url( home_url( '/users/#tab-companies' ) ); ?>" class="btn btn-sm btn-default">
							← Компании
						</a>
					</div>
				</div>
				<?php endif; ?>

				<!-- Алерт результата сохранения -->
				<div id="settings-alert" class="alert d-none m-b-20" role="alert"></div>
				<style>
					.crm-fintech-missing {
						border-color: #f55753 !important;
						background: #fff8f8 !important;
					}
					.crm-telegram-readonly {
						background: #f7f9fc !important;
					}
					.crm-fintech-status-line {
						font-size: 12px;
						color: #626c75;
						margin-bottom: 14px;
					}
					.crm-fintech-status-line strong {
						color: #1f2d3d;
					}
				</style>

				<!-- ─── Система / Таймзона ─────────────────────────────────────────── -->
				<div class="card card-default m-b-30">
					<div class="card-header">
						<div class="card-title">Система — Общие</div>
					</div>
					<div class="card-body">
						<form id="system-settings-form">
							<div class="row">
								<div class="col-md-5 col-lg-4">
									<div class="form-group">
										<label for="timezone">Часовой пояс (отображение дат)</label>
										<select class="full-width" id="timezone" name="timezone" data-init-plugin="select2">
											<?php
											$tz_groups = [
												'Универсальный' => [ 'UTC' ],
												'Россия' => [
													'Europe/Kaliningrad',
													'Europe/Moscow',
													'Europe/Samara',
													'Asia/Yekaterinburg',
													'Asia/Omsk',
													'Asia/Krasnoyarsk',
													'Asia/Irkutsk',
													'Asia/Yakutsk',
													'Asia/Vladivostok',
													'Asia/Magadan',
													'Asia/Kamchatka',
												],
												'Азия' => [
													'Asia/Dubai',
													'Asia/Bangkok',
													'Asia/Singapore',
													'Asia/Tokyo',
												],
												'Европа' => [
													'Europe/London',
													'Europe/Berlin',
													'Europe/Kiev',
												],
											];
											foreach ( $tz_groups as $group => $tzs ) {
												echo '<optgroup label="' . esc_attr( $group ) . '">';
												foreach ( $tzs as $tz ) {
													$label = $tz;
													try {
														$dtz    = new DateTimeZone( $tz );
														$offset = $dtz->getOffset( new DateTime( 'now', $dtz ) );
														$sign   = $offset >= 0 ? '+' : '-';
														$abs    = abs( $offset );
														$h      = (int) floor( $abs / 3600 );
														$m_m    = (int) floor( ( $abs % 3600 ) / 60 );
														$label  = 'UTC' . $sign . str_pad( $h, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( $m_m, 2, '0', STR_PAD_LEFT ) . ' — ' . $tz;
													} catch ( \Exception $e ) {}
													echo '<option value="' . esc_attr( $tz ) . '"' . selected( $current_tz, $tz, false ) . '>' . esc_html( $label ) . '</option>';
												}
												echo '</optgroup>';
											}
											?>
										</select>
										<p class="hint-text m-t-5">
											Даты в таблицах логов и ордеров конвертируются из UTC в выбранный пояс.<br>
											Убедитесь, что MySQL-сервер хранит даты в UTC.
										</p>
									</div>
								</div>
							</div>
							<button type="submit" class="btn btn-primary btn-cons">
								Сохранить
							</button>
						</form>
					</div>
				</div>

				<!-- ─── Telegram ───────────────────────────────────────────────────── -->
				<?php foreach ( $telegram_states as $telegram_context => $telegram_state ) : ?>
					<?php
					$telegram_context_label = (string) $telegram_state['label'];
					$telegram_settings      = (array) $telegram_state['settings'];
					$telegram_status        = (array) $telegram_state['status'];
					$telegram_missing_ids   = array_values( array_filter( array_map(
						static fn( $item ) => isset( $item['id'] ) ? (string) $item['id'] : '',
						$telegram_status['missing_fields'] ?? []
					) ) );
					$telegram_is_operator = $telegram_context === 'operator';
					$telegram_id_prefix   = 'telegram_' . $telegram_context;
					?>
					<div class="card card-default m-b-30" data-telegram-context-card="<?php echo esc_attr( $telegram_context ); ?>">
						<div class="card-header">
							<div class="card-title"><?php echo esc_html( $telegram_context_label ); ?></div>
						</div>
						<div class="card-body">
							<div id="<?php echo esc_attr( $telegram_id_prefix ); ?>_config_alert">
								<?php echo me_settings_render_telegram_status_html( $telegram_status ); ?>
							</div>
							<div id="<?php echo esc_attr( $telegram_id_prefix ); ?>_status_line" class="crm-fintech-status-line"></div>
							<form id="<?php echo esc_attr( $telegram_id_prefix ); ?>_settings_form"
							      class="telegram-settings-form"
							      data-telegram-context="<?php echo esc_attr( $telegram_context ); ?>"
							      data-saved-bot-username="<?php echo esc_attr( $telegram_settings['bot_username'] ?? '' ); ?>"
							      data-saved-bot-token="<?php echo esc_attr( $telegram_settings['bot_token'] ?? '' ); ?>">
								<div class="row">
									<div class="col-md-4">
										<div class="form-group">
											<label for="<?php echo esc_attr( $telegram_id_prefix ); ?>_bot_username">Имя бота</label>
											<input type="text"
											       class="form-control<?php echo in_array( crm_telegram_setting_key( $telegram_context, 'bot_username' ), $telegram_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?><?php echo ! empty( $telegram_status['webhook_lock'] ) ? ' crm-telegram-readonly' : ''; ?>"
											       id="<?php echo esc_attr( $telegram_id_prefix ); ?>_bot_username"
											       name="telegram_bot_username"
											       value="<?php echo esc_attr( $telegram_settings['bot_username'] ?? '' ); ?>"
											       placeholder="<?php echo $telegram_is_operator ? 'MalibuOperatorBot' : 'PhuketCashExchangeBot'; ?>"
											       autocomplete="off"
											       data-telegram-field="<?php echo esc_attr( crm_telegram_setting_key( $telegram_context, 'bot_username' ) ); ?>"
											       data-telegram-context="<?php echo esc_attr( $telegram_context ); ?>"
											       <?php echo ! empty( $telegram_status['webhook_lock'] ) ? 'readonly' : ''; ?>>
											<p class="hint-text m-t-5">
												Укажите username бота без символа <code>@</code>.
											</p>
										</div>
									</div>
									<div class="col-md-4">
										<div class="form-group">
											<label for="<?php echo esc_attr( $telegram_id_prefix ); ?>_bot_token">Токен бота (Bot Token)</label>
											<input type="text"
											       class="form-control<?php echo in_array( crm_telegram_setting_key( $telegram_context, 'bot_token' ), $telegram_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?><?php echo ! empty( $telegram_status['webhook_lock'] ) ? ' crm-telegram-readonly' : ''; ?>"
											       id="<?php echo esc_attr( $telegram_id_prefix ); ?>_bot_token"
											       name="telegram_bot_token"
											       value="<?php echo esc_attr( $telegram_settings['bot_token'] ?? '' ); ?>"
											       placeholder="1234567890:AAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
											       autocomplete="off"
											       data-telegram-field="<?php echo esc_attr( crm_telegram_setting_key( $telegram_context, 'bot_token' ) ); ?>"
											       data-telegram-context="<?php echo esc_attr( $telegram_context ); ?>"
											       <?php echo ! empty( $telegram_status['webhook_lock'] ) ? 'readonly' : ''; ?>>
											<p class="hint-text m-t-5">
												Получить токен можно у
												<a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>
												в Telegram.
											</p>
										</div>
									</div>
									<div class="col-md-4">
										<div class="form-group">
											<label for="<?php echo esc_attr( $telegram_id_prefix ); ?>_callback_url">Callback URL</label>
											<input type="text"
											       class="form-control crm-telegram-readonly"
											       id="<?php echo esc_attr( $telegram_id_prefix ); ?>_callback_url"
											       value="<?php echo esc_attr( $telegram_status['callback_url'] ?? '' ); ?>"
											       readonly>
											<p class="hint-text m-t-5">
												<?php if ( $telegram_is_operator ) : ?>
													Отдельный callback для операторского бота текущей компании.
												<?php else : ?>
													Отдельный callback для мерчантского бота текущей компании.
												<?php endif; ?>
											</p>
										</div>
									</div>
								</div>
								<div class="d-flex flex-wrap gap-2">
									<button type="submit" class="btn btn-primary btn-cons btn-save-telegram-settings">
										Сохранить настройки
									</button>
									<button type="button" class="btn btn-success btn-cons btn-telegram-connect" data-telegram-context="<?php echo esc_attr( $telegram_context ); ?>">
										Подключить callback
									</button>
									<button type="button" class="btn btn-default btn-cons btn-telegram-unlock<?php echo empty( $telegram_status['webhook_lock'] ) ? ' d-none' : ''; ?>" data-telegram-context="<?php echo esc_attr( $telegram_context ); ?>">
										Разблокировать редактирование
									</button>
								</div>
							</form>
						</div>
					</div>
				<?php endforeach; ?>

				<!-- ─── Курсы (по парам) ─────────────────────────────────────────── -->
				<?php if ( function_exists( 'crm_is_root' ) && crm_is_root( get_current_user_id() ) ) : ?>
					<div class="alert alert-info m-b-20" role="alert">
						<i class="pg-icon m-r-5">settings</i>
						<strong>Root.</strong> Активация и деактивация валютных пар выполняется на отдельной странице
						<a href="<?php echo esc_url( home_url( '/root-rate-pairs/' ) ); ?>"
						   class="alert-link semi-bold m-l-5">
							Курсы и пары
						</a>.
						Здесь, в настройках компании, задаются <strong>тип</strong> (сдвиг / процент) и <strong>значение</strong> наценки.
					</div>
				<?php endif; ?>

				<?php foreach ( $rates_pair_blocks as $rates_block ) : ?>
					<?php get_template_part( 'template-parts/rates-coefficient-block', null, $rates_block ); ?>
				<?php endforeach; ?>

				<?php if ( $merchant_settings ) : ?>
				<div class="card card-default m-b-30">
					<div class="card-header">
						<div class="card-title">Мерчанты — Базовые настройки</div>
					</div>
					<div class="card-body">
						<form id="merchant-settings-form">
							<div class="row">
								<div class="col-md-3">
									<div class="form-group">
										<label for="merchant_invite_ttl_minutes">TTL приглашения (минуты)</label>
										<input type="number"
										       class="form-control"
										       id="merchant_invite_ttl_minutes"
										       name="merchant_invite_ttl_minutes"
										       min="1"
										       step="1"
										       value="<?php echo esc_attr( $merchant_settings['invite_ttl_minutes'] ); ?>">
										<p class="hint-text m-t-5">На сколько минут действует одноразовый invite_token.</p>
									</div>
								</div>
								<div class="col-md-3">
									<div class="form-group">
										<label for="merchant_default_platform_fee_type">Наша fee — тип</label>
										<select id="merchant_default_platform_fee_type" name="merchant_default_platform_fee_type" class="full-width" data-init-plugin="select2">
											<?php foreach ( crm_merchant_markup_types() as $fee_type => $fee_label ) : ?>
											<option value="<?php echo esc_attr( $fee_type ); ?>" <?php selected( $merchant_settings['default_platform_fee_type'], $fee_type ); ?>>
												<?php echo esc_html( $fee_label ); ?>
											</option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
								<div class="col-md-3">
									<div class="form-group">
										<label for="merchant_default_platform_fee_value">Наша fee — значение</label>
										<input type="number"
										       class="form-control"
										       id="merchant_default_platform_fee_value"
										       name="merchant_default_platform_fee_value"
										       step="0.00000001"
										       min="0"
										       value="<?php echo esc_attr( $merchant_settings['default_platform_fee_value'] ); ?>">
									</div>
								</div>
								<div class="col-md-3">
									<div class="form-group">
										<label>&nbsp;</label>
										<div class="checkbox check-success" style="margin-top:7px">
											<input type="checkbox" id="merchant_bonus_enabled" name="merchant_bonus_enabled" value="1" <?php checked( $merchant_settings['bonus_enabled'] ); ?>>
											<label for="merchant_bonus_enabled">Бонусный контур включён</label>
										</div>
										<div class="checkbox check-success m-t-10">
											<input type="checkbox" id="merchant_referral_enabled" name="merchant_referral_enabled" value="1" <?php checked( $merchant_settings['referral_enabled'] ); ?>>
											<label for="merchant_referral_enabled">Реферальный контур включён</label>
										</div>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-3">
									<div class="form-group">
										<label for="merchant_referral_reward_type">Рефералка — тип</label>
										<select id="merchant_referral_reward_type" name="merchant_referral_reward_type" class="full-width" data-init-plugin="select2">
											<?php foreach ( crm_merchant_markup_types() as $fee_type => $fee_label ) : ?>
											<option value="<?php echo esc_attr( $fee_type ); ?>" <?php selected( $merchant_settings['referral_reward_type'], $fee_type ); ?>>
												<?php echo esc_html( $fee_label ); ?>
											</option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
								<div class="col-md-3">
									<div class="form-group">
										<label for="merchant_referral_reward_value">Рефералка — значение</label>
										<input type="number"
										       class="form-control"
										       id="merchant_referral_reward_value"
										       name="merchant_referral_reward_value"
										       step="0.00000001"
										       min="0"
										       value="<?php echo esc_attr( $merchant_settings['referral_reward_value'] ); ?>">
										<p class="hint-text m-t-5">Базовая схема начисления для будущего WebApp / bot-flow.</p>
									</div>
								</div>
							</div>
							<button type="submit" class="btn btn-primary btn-cons">
								Сохранить настройки мерчантов
							</button>
						</form>
					</div>
				</div>
				<?php endif; ?>

				<!-- ─── Fintech: Общие ──────────────────────────────────────── -->
				<div id="fintech-config-alert">
					<?php echo me_settings_render_fintech_status_html( $fintech_status ); ?>
				</div>
				<div class="card card-default m-b-20">
					<div class="card-header">
						<div class="card-title">Платёжный шлюз — Общие настройки</div>
					</div>
					<div class="card-body">
						<div id="fintech-general-status" class="crm-fintech-status-line"></div>
						<form id="fintech-settings-form">
							<div class="row">
								<div class="col-md-4 col-lg-3">
									<div class="form-group">
										<label for="fintech_company_name">Название компании</label>
										<input type="text" class="form-control<?php echo in_array( 'fintech_company_name', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_company_name" name="fintech_company_name" data-fintech-field="fintech_company_name"
										       value="<?php echo esc_attr( $fintech['company_name'] ); ?>" placeholder="Malibu Exchange">
									</div>
								</div>
								<div class="col-md-3 col-lg-2">
									<div class="form-group">
										<label for="fintech_merchant_order_prefix">Префикс ордера</label>
										<input type="text" class="form-control<?php echo in_array( 'fintech_merchant_order_prefix', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_merchant_order_prefix" name="fintech_merchant_order_prefix" data-fintech-field="fintech_merchant_order_prefix"
										       value="<?php echo esc_attr( $fintech['merchant_order_prefix'] ); ?>" placeholder="MALIBU" maxlength="16">
									</div>
								</div>
									<div class="col-md-3 col-lg-2">
										<div class="form-group">
											<label for="fintech_active_provider">Активный провайдер</label>
											<select class="full-width<?php echo in_array( 'fintech_active_provider', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_active_provider" name="fintech_active_provider" data-init-plugin="select2" data-fintech-field="fintech_active_provider">
												<option value="" <?php selected( $fintech_active_provider_allowed ? $fintech['active_provider'] : '', '' ); ?>>
													<?php echo empty( $fintech_allowed_providers ) ? 'Нет доступных контуров' : 'Выберите доступный контур'; ?>
												</option>
												<?php foreach ( $fintech_allowed_providers as $provider_code ) : ?>
													<option value="<?php echo esc_attr( $provider_code ); ?>" <?php selected( $fintech_active_provider_allowed ? $fintech['active_provider'] : '', $provider_code ); ?>>
														<?php echo esc_html( $fintech_provider_labels[ $provider_code ] ?? $provider_code ); ?>
													</option>
												<?php endforeach; ?>
											</select>
											<p class="hint-text m-t-5">Список доступных контуров задаётся root на странице компаний.</p>
										</div>
									</div>
								<div class="col-md-2">
									<div class="form-group">
										<label>&nbsp;</label>
										<div class="checkbox check-success" style="margin-top:7px">
											<input type="checkbox" id="fintech_debug" name="fintech_debug" value="1" <?php checked( $fintech['debug'] ); ?>>
											<label for="fintech_debug">Debug-лог</label>
										</div>
									</div>
								</div>
							</div>
							<button type="submit" class="btn btn-primary btn-cons">
								Сохранить
							</button>
						</form>
					</div>
				</div>

					<!-- ─── Fintech: Kanyon / Pay2Day ────────────────────────────── -->
					<?php if ( in_array( 'kanyon', $fintech_allowed_providers, true ) ) : ?>
					<div class="card card-default m-b-20">
						<div class="card-header">
							<div class="card-title">Kanyon / Pay2Day — Учётные данные</div>
					</div>
					<div class="card-body">
						<div id="fintech-kanyon-status" class="crm-fintech-status-line"></div>
						<form id="fintech-kanyon-form">
							<div class="row">
								<div class="col-md-4">
									<div class="form-group">
										<label for="fintech_pay2day_login">Логин (Login)</label>
										<input type="text" class="form-control<?php echo in_array( 'fintech_pay2day_login', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_pay2day_login" name="fintech_pay2day_login" data-fintech-field="fintech_pay2day_login"
										       value="<?php echo esc_attr( $fintech['pay2day_login'] ); ?>"
										       placeholder="your@login" autocomplete="off">
									</div>
								</div>
								<div class="col-md-4">
									<div class="form-group">
										<label for="fintech_pay2day_password">Пароль (Password)</label>
										<input type="password" class="form-control<?php echo in_array( 'fintech_pay2day_password', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_pay2day_password" name="fintech_pay2day_password" data-fintech-field="fintech_pay2day_password"
										       value="<?php echo esc_attr( $fintech['pay2day_password'] ); ?>"
										       placeholder="••••••••" autocomplete="new-password">
									</div>
								</div>
								<div class="col-md-2">
									<div class="form-group">
										<label for="fintech_pay2day_tsp_id">TSP ID</label>
										<input type="number" class="form-control<?php echo in_array( 'fintech_pay2day_tsp_id', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_pay2day_tsp_id" name="fintech_pay2day_tsp_id" data-fintech-field="fintech_pay2day_tsp_id"
										       value="<?php echo esc_attr( $fintech['pay2day_tsp_id'] ); ?>" min="0" placeholder="0">
									</div>
								</div>
								<div class="col-md-2">
									<div class="form-group">
										<label for="fintech_pay2day_order_currency">Код валюты</label>
										<input type="text" class="form-control<?php echo in_array( 'fintech_pay2day_order_currency', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_pay2day_order_currency" name="fintech_pay2day_order_currency" data-fintech-field="fintech_pay2day_order_currency"
										       value="<?php echo esc_attr( $fintech['pay2day_order_currency'] ); ?>"
										       placeholder="USDT" maxlength="8">
										<p class="hint-text m-t-3">Например: USDT</p>
									</div>
								</div>
							</div>
							<div class="row m-t-10 m-b-10">
								<div class="col-md-8">
									<div class="checkbox check-success">
										<input type="checkbox" id="fintech_kanyon_verify_signature" name="fintech_kanyon_verify_signature" value="1"
										       <?php checked( $fintech['kanyon_verify_signature'] ); ?>>
										<label for="fintech_kanyon_verify_signature">Проверять подпись callback (HMAC)</label>
									</div>
								</div>
							</div>
							<div class="row" id="kanyon-pubkey-row"<?php echo $fintech['kanyon_verify_signature'] ? '' : ' style="display:none"'; ?>>
								<div class="col-md-8">
									<div class="form-group">
										<label for="fintech_kanyon_public_key_pem">Публичный ключ провайдера (PEM)</label>
										<textarea class="form-control<?php echo in_array( 'fintech_kanyon_public_key_pem', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_kanyon_public_key_pem" name="fintech_kanyon_public_key_pem" data-fintech-field="fintech_kanyon_public_key_pem"
										          rows="5" style="font-family:monospace;font-size:12px"><?php echo esc_textarea( $fintech['kanyon_public_key_pem'] ); ?></textarea>
									</div>
								</div>
							</div>
							<button type="submit" class="btn btn-primary btn-cons m-t-10">
								Сохранить Kanyon
							</button>
							</form>
						</div>
					</div>
					<?php endif; ?>

					<!-- ─── Fintech: Doverka ──────────────────────────────────────── -->
					<?php if ( in_array( 'doverka', $fintech_allowed_providers, true ) ) : ?>
					<div class="card card-default m-b-30">
						<div class="card-header">
							<div class="card-title">Doverka — Учётные данные</div>
					</div>
					<div class="card-body">
						<div id="fintech-doverka-status" class="crm-fintech-status-line"></div>
						<form id="fintech-doverka-form">
							<div class="row">
								<div class="col-md-5">
									<div class="form-group">
										<label for="fintech_doverka_api_key">API Key</label>
										<input type="password" class="form-control<?php echo in_array( 'fintech_doverka_api_key', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_doverka_api_key" name="fintech_doverka_api_key" data-fintech-field="fintech_doverka_api_key"
										       value="<?php echo esc_attr( $fintech['doverka_api_key'] ); ?>"
										       placeholder="••••••••••••••••" autocomplete="new-password">
									</div>
								</div>
								<div class="col-md-2">
									<div class="form-group">
										<label for="fintech_doverka_currency_id">Currency ID</label>
										<input type="number" class="form-control<?php echo in_array( 'fintech_doverka_currency_id', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_doverka_currency_id" name="fintech_doverka_currency_id" data-fintech-field="fintech_doverka_currency_id"
										       value="<?php echo esc_attr( $fintech['doverka_currency_id'] ); ?>" min="0" placeholder="0">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-5">
									<div class="form-group">
										<label for="fintech_doverka_approve_url">Approve URL</label>
										<input type="url" class="form-control<?php echo in_array( 'fintech_doverka_approve_url', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_doverka_approve_url" name="fintech_doverka_approve_url" data-fintech-field="fintech_doverka_approve_url"
										       value="<?php echo esc_attr( $fintech['doverka_approve_url'] ); ?>" placeholder="https://...">
									</div>
								</div>
								<div class="col-md-5">
									<div class="form-group">
										<label for="fintech_doverka_kyc_redirect_url">KYC Redirect URL</label>
										<input type="url" class="form-control<?php echo in_array( 'fintech_doverka_kyc_redirect_url', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_doverka_kyc_redirect_url" name="fintech_doverka_kyc_redirect_url" data-fintech-field="fintech_doverka_kyc_redirect_url"
										       value="<?php echo esc_attr( $fintech['doverka_kyc_redirect_url'] ); ?>" placeholder="https://...">
									</div>
								</div>
							</div>
							<button type="submit" class="btn btn-primary btn-cons">
								Сохранить Doverka
							</button>
							</form>
						</div>
					</div>
					<?php endif; ?>

			</div>
		</div>
		<?php get_template_part( 'template-parts/footer-backoffice' ); ?>
	</div>
	</div>

		<div class="modal fade" id="telegram-callback-confirm-modal" tabindex="-1" role="dialog" aria-hidden="true">
			<div class="modal-dialog modal-dialog-centered modal-sm">
				<div class="modal-content">
					<div class="modal-header clearfix text-left">
						<button aria-label="Закрыть" type="button" class="close" data-bs-dismiss="modal" aria-hidden="true">
							<i class="pg-icon">close</i>
						</button>
						<h5 id="telegram-callback-confirm-title">Подключить callback</h5>
					</div>
					<div class="modal-body">
						<p class="no-margin" id="telegram-callback-confirm-text"></p>
						<div class="m-t-15">
							<div class="hint-text fs-12 text-uppercase">Бот</div>
							<div class="semi-bold" id="telegram-callback-confirm-bot">—</div>
						</div>
						<div class="m-t-10">
							<div class="hint-text fs-12 text-uppercase">Токен</div>
							<div class="semi-bold" id="telegram-callback-confirm-token">—</div>
						</div>
					</div>
					<div class="modal-footer">
						<button aria-label="Подключить callback" type="button" class="btn btn-success pull-left inline" id="telegram-callback-confirm-ok">Подключить</button>
						<button aria-label="Отмена" type="button" class="btn btn-default no-margin pull-left inline" data-bs-dismiss="modal">Отмена</button>
					</div>
				</div>
			</div>
		</div>

	<?php get_template_part( 'template-parts/quickview' ); ?>
	<?php get_template_part( 'template-parts/overlay' ); ?>
	<?php get_template_part( 'template-parts/toast-host' ); ?>

<?php
add_action( 'wp_footer', function () use ( $settings_js_bootstrap ) {
?>
<script type="application/json" id="settings-page-bootstrap"><?php echo crm_json_for_inline_js( $settings_js_bootstrap ); ?></script>
<script>
	(function ($) {
		'use strict';

		function readBootstrapJson(id, fallback) {
			var node = document.getElementById(id);
			if (!node) {
				return fallback;
			}
			try {
				return JSON.parse(node.textContent || '{}');
			} catch (err) {
				console.error('Bootstrap JSON parse failed for', id, err);
				return fallback;
			}
		}

		var BOOTSTRAP = readBootstrapJson('settings-page-bootstrap', {});
		var AJAX_URL = BOOTSTRAP.ajax_url || '';
		var NONCE    = BOOTSTRAP.nonce || '';
		var FINTECH_ALLOWED_PROVIDERS = BOOTSTRAP.fintech_allowed_providers || [];
		var FINTECH_PROVIDER_LABELS   = BOOTSTRAP.fintech_provider_labels || {};
		var TELEGRAM_STATUSES         = BOOTSTRAP.telegram_statuses || {};
		if (!TELEGRAM_STATUSES.merchant && BOOTSTRAP.telegram_status) {
			TELEGRAM_STATUSES.merchant = BOOTSTRAP.telegram_status || {};
		}
		var FINTECH_FORM_SELECTOR = '#fintech-settings-form, #fintech-kanyon-form, #fintech-doverka-form';
		var pendingTelegramCallbackConnect = null;
		var telegramCallbackConfirmModal = null;

	function escapeHtml(value) {
		return $('<div>').text(value == null ? '' : String(value)).html();
	}

	function getTrimmedValue(selector) {
		return $.trim($(selector).val() || '');
	}

	function normalizeTelegramContext(context) {
		return context === 'operator' ? 'operator' : 'merchant';
	}

	function telegramSelector(context, suffix) {
		return '#telegram_' + normalizeTelegramContext(context) + '_' + suffix;
	}

	function oppositeTelegramContext(context) {
		return normalizeTelegramContext(context) === 'operator' ? 'merchant' : 'operator';
	}

	function telegramForm(context) {
		return $(telegramSelector(context, 'settings_form'));
	}

	function getSavedTelegramUsername(context) {
		return $.trim(telegramForm(context).attr('data-saved-bot-username') || '');
	}

	function getSavedTelegramToken(context) {
		return $.trim(telegramForm(context).attr('data-saved-bot-token') || '');
	}

	function getCurrentTelegramUsername(context) {
		return getTrimmedValue(telegramSelector(context, 'bot_username')).replace(/^@+/, '');
	}

	function getCurrentTelegramToken(context) {
		return getTrimmedValue(telegramSelector(context, 'bot_token'));
	}

	function isTelegramDirty(context) {
		context = normalizeTelegramContext(context);
		return getCurrentTelegramUsername(context) !== getSavedTelegramUsername(context)
			|| getCurrentTelegramToken(context) !== getSavedTelegramToken(context);
	}

	function markTelegramSaved(context) {
		context = normalizeTelegramContext(context);
		telegramForm(context)
			.attr('data-saved-bot-username', getCurrentTelegramUsername(context))
			.attr('data-saved-bot-token', getCurrentTelegramToken(context));
	}

	function maskTelegramToken(token) {
		token = $.trim(token || '');
		if (!token) {
			return 'не сохранён';
		}
		if (token.length <= 8) {
			return '••••';
		}
		return token.substring(0, 4) + '…' + token.substring(token.length - 6);
	}

	function showTelegramCallbackConfirmModal() {
		var node = document.getElementById('telegram-callback-confirm-modal');
		if (!node) {
			return;
		}
		if (window.bootstrap && bootstrap.Modal) {
			if (!telegramCallbackConfirmModal) {
				telegramCallbackConfirmModal = new bootstrap.Modal(node);
			}
			telegramCallbackConfirmModal.show();
			return;
		}
		$('#telegram-callback-confirm-modal').modal('show');
	}

	function hideTelegramCallbackConfirmModal() {
		var $modal = $('#telegram-callback-confirm-modal');
		if (telegramCallbackConfirmModal) {
			telegramCallbackConfirmModal.hide();
			return;
		}
		$modal.modal('hide');
	}

	function openTelegramCallbackConfirm($btn, context, savedUsername, savedToken, label) {
		context = normalizeTelegramContext(context);
		var alreadyConnected = !!(TELEGRAM_STATUSES[context] && TELEGRAM_STATUSES[context].webhook_ready);
		pendingTelegramCallbackConnect = {
			button: $btn,
			context: context
		};
		$('#telegram-callback-confirm-title').text(alreadyConnected ? 'Зафиксировать callback' : 'Подключить callback');
		$('#telegram-callback-confirm-text').text(
			alreadyConnected
				? 'Callback уже зарегистрирован для ' + label + '. Мы повторно применим сохранённый webhook и зафиксируем блок редактирования.'
				: 'Callback будет зарегистрирован для ' + label + ' с данными, которые уже сохранены в базе.'
		);
		$('#telegram-callback-confirm-bot').text('@' + savedUsername);
		$('#telegram-callback-confirm-token').text(maskTelegramToken(savedToken));
		$('#telegram-callback-confirm-ok').text(alreadyConnected ? 'Зафиксировать' : 'Подключить');
		showTelegramCallbackConfirmModal();
	}

		function collectFintechStatus() {
			var provider = getTrimmedValue('#fintech_active_provider').toLowerCase();
			var providerLabels = FINTECH_PROVIDER_LABELS || {};
			var allowedProviders = (FINTECH_ALLOWED_PROVIDERS || []).slice();
			var missingGeneral = [];
			var missingProvider = [];
			var providerSectionLabel = '';
			var blockedReason = '';
			var providerUnavailable = false;
			var allowedProviderLabels = $.map(allowedProviders, function (providerCode) {
				return providerLabels[providerCode] || providerCode;
			});

			if (!getTrimmedValue('#fintech_company_name')) {
				missingGeneral.push({ id: 'fintech_company_name', label: 'Название компании' });
		}
			if (!getTrimmedValue('#fintech_merchant_order_prefix')) {
				missingGeneral.push({ id: 'fintech_merchant_order_prefix', label: 'Префикс ордера' });
			}
			if (!allowedProviders.length) {
				missingGeneral.push({ id: 'fintech_active_provider', label: 'Активный провайдер' });
				blockedReason = 'Для этой компании сейчас отключены все платёжные контуры.';
				provider = '';
			} else if (provider && allowedProviders.indexOf(provider) === -1) {
				missingGeneral.push({ id: 'fintech_active_provider', label: 'Активный провайдер' });
				blockedReason = 'Контур ' + (providerLabels[provider] || provider) + ' отключён в настройках компании. Выберите другой доступный контур.';
				providerUnavailable = true;
				provider = '';
			} else if (!providerLabels[provider]) {
				missingGeneral.push({ id: 'fintech_active_provider', label: 'Активный провайдер' });
				provider = '';
			}

			if (provider === 'kanyon' && $('#fintech-kanyon-form').length) {
				providerSectionLabel = 'Kanyon / Pay2Day — Учётные данные';
				if (!getTrimmedValue('#fintech_pay2day_login')) {
					missingProvider.push({ id: 'fintech_pay2day_login', label: 'Логин (Login)' });
			}
			if (!getTrimmedValue('#fintech_pay2day_password')) {
				missingProvider.push({ id: 'fintech_pay2day_password', label: 'Пароль (Password)' });
			}
			if ((parseInt($('#fintech_pay2day_tsp_id').val(), 10) || 0) <= 0) {
				missingProvider.push({ id: 'fintech_pay2day_tsp_id', label: 'TSP ID' });
			}
			if (!getTrimmedValue('#fintech_pay2day_order_currency')) {
				missingProvider.push({ id: 'fintech_pay2day_order_currency', label: 'Код валюты' });
			}
			if ($('#fintech_kanyon_verify_signature').is(':checked') && !getTrimmedValue('#fintech_kanyon_public_key_pem')) {
				missingProvider.push({ id: 'fintech_kanyon_public_key_pem', label: 'Публичный ключ провайдера (PEM)' });
			}
			} else if (provider === 'doverka' && $('#fintech-doverka-form').length) {
				providerSectionLabel = 'Doverka — Учётные данные';
				if (!getTrimmedValue('#fintech_doverka_api_key')) {
					missingProvider.push({ id: 'fintech_doverka_api_key', label: 'API Key' });
			}
			if ((parseInt($('#fintech_doverka_currency_id').val(), 10) || 0) <= 0) {
				missingProvider.push({ id: 'fintech_doverka_currency_id', label: 'Currency ID' });
			}
			if (!getTrimmedValue('#fintech_doverka_approve_url')) {
				missingProvider.push({ id: 'fintech_doverka_approve_url', label: 'Approve URL' });
			}
			if (!getTrimmedValue('#fintech_doverka_kyc_redirect_url')) {
				missingProvider.push({ id: 'fintech_doverka_kyc_redirect_url', label: 'KYC Redirect URL' });
			}
		}

		return {
			is_configured: provider !== '' && missingGeneral.length === 0 && missingProvider.length === 0,
			provider: provider,
				provider_label: provider ? providerLabels[provider] : 'Не выбран',
				general_section_label: 'Платёжный шлюз — Общие настройки',
				provider_section_label: providerSectionLabel,
				missing_general: missingGeneral,
				missing_provider: missingProvider,
				missing_fields: missingGeneral.concat(missingProvider),
				allowed_providers: allowedProviders,
				allowed_provider_labels: allowedProviderLabels,
				provider_unavailable: providerUnavailable,
				blocked_reason: blockedReason
			};
		}

	function labelsFromItems(items) {
		return $.map(items || [], function (item) {
			return item && item.label ? item.label : null;
		});
	}

		function buildFintechAlertHtml(status) {
			var missingGeneral = labelsFromItems(status.missing_general);
			var missingProvider = labelsFromItems(status.missing_provider);
			var allowedProviderLabels = status.allowed_provider_labels || [];

			if (status.is_configured) {
				return '' +
				'<div class="alert alert-success bordered m-b-15">' +
					'<strong>Платёжный шлюз готов к работе.</strong><br>' +
					'Активный провайдер: ' + escapeHtml(status.provider_label) + '.' +
				'</div>';
		}

			var html = '' +
				'<div class="alert alert-danger bordered m-b-15">' +
					'<strong>Платёжные ордера сейчас заблокированы.</strong><br>';

			if (status.blocked_reason) {
				html += escapeHtml(status.blocked_reason);
			} else if (status.provider) {
				html += 'Сейчас выбран провайдер: ' + escapeHtml(status.provider_label) + '.';
			} else {
				html += 'Сначала выберите активный провайдер в блоке общих настроек.';
			}

			if (allowedProviderLabels.length) {
				html += '<div class="m-t-10"><strong>Доступные контуры:</strong> ' + escapeHtml(allowedProviderLabels.join(', ')) + '.</div>';
			}

			if (missingGeneral.length || missingProvider.length) {
				html += '<div class="m-t-10"><div class="bold fs-12">Что именно нужно заполнить:</div><ul class="m-b-0 p-l-20">';
				if (missingGeneral.length) {
				html += '<li>В блоке «' + escapeHtml(status.general_section_label) + '»: ' + escapeHtml(missingGeneral.join(', ')) + '.</li>';
			}
			if (missingProvider.length) {
				html += '<li>В блоке «' + escapeHtml(status.provider_section_label || 'Настройки провайдера') + '»: ' + escapeHtml(missingProvider.join(', ')) + '.</li>';
			}
			html += '</ul></div><div class="m-t-10">После заполнения нажмите «Сохранить» в общем блоке и в блоке активного провайдера.</div>';
		}

		html += '</div>';

		return html;
	}

		function renderFintechBlockStatuses(status) {
			var generalText = (status.missing_general || []).length
				? '<strong>Не хватает в этом блоке:</strong> ' + escapeHtml(labelsFromItems(status.missing_general).join(', ')) + '.'
				: '<strong>Общий блок заполнен.</strong> Здесь должны быть название компании, префикс ордера и активный провайдер.';
			$('#fintech-general-status').html(generalText);

			if (status.provider === 'kanyon' && $('#fintech-kanyon-status').length) {
				$('#fintech-kanyon-status').html(
					(status.missing_provider || []).length
						? '<strong>Это активный провайдер.</strong> Не хватает: ' + escapeHtml(labelsFromItems(status.missing_provider).join(', ')) + '.'
						: '<strong>Это активный провайдер.</strong> Блок Kanyon заполнен.'
				);
				if ($('#fintech-doverka-status').length) {
					$('#fintech-doverka-status').html('Этот блок сейчас не обязателен. Он нужен только если активный провайдер = Doverka.');
				}
			} else if (status.provider === 'doverka' && $('#fintech-doverka-status').length) {
				$('#fintech-doverka-status').html(
					(status.missing_provider || []).length
						? '<strong>Это активный провайдер.</strong> Не хватает: ' + escapeHtml(labelsFromItems(status.missing_provider).join(', ')) + '.'
						: '<strong>Это активный провайдер.</strong> Блок Doverka заполнен.'
				);
				if ($('#fintech-kanyon-status').length) {
					$('#fintech-kanyon-status').html('Этот блок сейчас не обязателен. Он нужен только если активный провайдер = Kanyon.');
				}
			} else {
				if ($('#fintech-kanyon-status').length) {
					$('#fintech-kanyon-status').html('Сначала выберите активный провайдер в общем блоке.');
				}
				if ($('#fintech-doverka-status').length) {
					$('#fintech-doverka-status').html('Сначала выберите активный провайдер в общем блоке.');
				}
			}
		}

	function highlightFintechField(fieldId, isMissing) {
		var $field = $('#' + fieldId);
		$field.toggleClass('crm-fintech-missing', !!isMissing);
		if ($field.hasClass('select2-hidden-accessible')) {
			$field.next('.select2').find('.select2-selection').toggleClass('crm-fintech-missing', !!isMissing);
		}
	}

	function renderFintechStatus(status) {
		var missingIds = {};
		$.each(status.missing_fields || [], function (_, item) {
			if (item && item.id) {
				missingIds[item.id] = true;
			}
		});

		$('#fintech-config-alert').html(buildFintechAlertHtml(status));
		renderFintechBlockStatuses(status);

		$('[data-fintech-field]').each(function () {
			highlightFintechField($(this).attr('id'), !!missingIds[$(this).attr('id')]);
		});
	}

	function buildTelegramAlertHtml(status) {
		var missingLabels = labelsFromItems(status.missing_fields || []);
		var isOperator = normalizeTelegramContext(status.context) === 'operator';
		var ready = isOperator ? status.operator_ready : status.invite_ready;
		var readyTitle = isOperator ? 'Операторский Telegram-бот готов к работе.' : 'Создание Telegram invite-ссылок доступно.';
		var readyText = isOperator
			? 'Операторский контур может принимать команды через отдельный бот.'
			: 'Администраторы компаний могут создавать новые invite-ссылки и QR-коды для подключения мерчантов.';
		var blockedTitle = isOperator ? 'Операторский Telegram-бот сейчас заблокирован.' : 'Создание Telegram invite-ссылок заблокировано.';
		var blockedDefault = isOperator
			? 'Чтобы включить операторский контур, заполните имя и токен бота в этом разделе.'
			: 'Чтобы включить приглашения мерчантов, заполните имя бота и токен бота в этом разделе.';

		if (ready) {
			return '' +
				'<div class="alert alert-success bordered m-b-15">' +
					'<strong>' + escapeHtml(readyTitle) + '</strong><br>' +
					(status.bot_handle ? 'Бот: ' + escapeHtml(status.bot_handle) + '. ' : '') +
					escapeHtml(readyText) +
				'</div>';
		}

		if (status.is_configured) {
			var warningTitle = isOperator
				? 'Callback операторского бота не подключён.'
				: 'Callback мерчантского бота не подключён.';
			var warningHtml = '' +
				'<div class="alert alert-warning bordered m-b-15">' +
					'<strong>' + escapeHtml(warningTitle) + '</strong><br>' +
					escapeHtml(status.blocked_reason || 'Нажмите «Подключить callback», чтобы зарегистрировать webhook для этой компании.');
			if (!isOperator) {
				warningHtml += '<div class="m-t-10">Это не статус мерчантов: уже созданные мерчанты и их активность в таблице не меняются. Ограничено только создание новых Telegram invite-ссылок.</div>';
			}
			if (status.webhook_last_error) {
				warningHtml += '<div class="m-t-10"><strong>Последняя ошибка Telegram API:</strong> ' + escapeHtml(status.webhook_last_error) + '</div>';
			}
			warningHtml += '</div>';
			return warningHtml;
		}

		var dangerHtml = '' +
			'<div class="alert alert-danger bordered m-b-15">' +
				'<strong>' + escapeHtml(blockedTitle) + '</strong><br>' +
				escapeHtml(status.blocked_reason || blockedDefault);
		if (missingLabels.length) {
			dangerHtml += '<div class="m-t-10"><div class="bold fs-12">Что нужно исправить:</div><ul class="m-b-0 p-l-20"><li>' + escapeHtml(missingLabels.join(', ')) + '</li></ul></div>';
		}
		dangerHtml += '</div>';
		return dangerHtml;
	}

	function renderTelegramStatusLine(status) {
		var context = normalizeTelegramContext(status.context);
		var ready = context === 'operator' ? status.operator_ready : status.invite_ready;
		var label = context === 'operator' ? 'Операторский бот' : 'Мерчантский бот';
		var hasDuplicates = (status.duplicate_fields || []).length > 0;
		var hasUnsaved = !!status.has_unsaved_changes;
		var text = '';
		if (ready) {
			text = context === 'operator'
				? '<strong>' + label + ': callback зарегистрирован.</strong> Бот готов принимать команды.'
				: '<strong>' + label + ': callback зарегистрирован.</strong> Можно создавать новые Telegram invite-ссылки.';
			if (status.webhook_connected_at) {
				text += ' Подключено: ' + escapeHtml(status.webhook_connected_at) + '.';
			}
		} else if (hasDuplicates) {
			text = '<strong>' + label + ': требуется исправить настройки.</strong> Этот бот уже используется во втором Telegram-контуре.';
		} else if (!status.is_configured) {
			text = '<strong>' + label + ': нужны данные бота.</strong> Заполните имя бота и токен.';
		} else if (hasUnsaved) {
			text = '<strong>' + label + ': есть несохранённые изменения.</strong> Сначала сохраните настройки, затем подключите callback.';
		} else if (status.is_configured) {
			text = context === 'operator'
				? '<strong>' + label + ': данные сохранены.</strong> Осталось нажать «Подключить callback».'
				: '<strong>' + label + ': данные сохранены.</strong> Новые Telegram invite-ссылки станут доступны после подключения callback.';
		}
		if (status.webhook_lock) {
			text += ' Блок зафиксирован; для правок нажмите «Разблокировать редактирование».';
		} else if (status.webhook_ready && !hasUnsaved && !hasDuplicates) {
			text += ' Редактирование сейчас разблокировано; чтобы снова зафиксировать блок, нажмите «Зафиксировать callback».';
		}
		$(telegramSelector(context, 'status_line')).html(text);
	}

	function highlightTelegramField(fieldId, isMissing) {
		$('#' + fieldId).toggleClass('crm-fintech-missing', !!isMissing);
	}

	function collectTelegramDuplicate(context) {
		context = normalizeTelegramContext(context);
		var otherContext = oppositeTelegramContext(context);
		var username = getCurrentTelegramUsername(context);
		var token = getCurrentTelegramToken(context);
		var otherUsername = getCurrentTelegramUsername(otherContext);
		var otherToken = getCurrentTelegramToken(otherContext);
		var fields = [];
		var otherLabel = otherContext === 'operator' ? 'Операторский бот' : 'Мерчантский бот';

		if (token && otherToken && token === otherToken) {
			fields.push({ id: 'telegram_' + context + '_bot_token', label: 'Токен бота' });
		}
		if (username && otherUsername && username.toLowerCase() === otherUsername.toLowerCase()) {
			fields.push({ id: 'telegram_' + context + '_bot_username', label: 'Имя бота' });
		}

		return {
			has_duplicate: fields.length > 0,
			fields: fields,
			message: fields.length
				? 'Нельзя использовать одного Telegram-бота в двух контурах. Измените токен или username: совпадение найдено в блоке «' + otherLabel + '».'
				: ''
		};
	}

	function collectTelegramDraftStatus(context) {
		context = normalizeTelegramContext(context);
		var status = $.extend(true, {}, TELEGRAM_STATUSES[context] || {});
		var username = getCurrentTelegramUsername(context);
		var token = getCurrentTelegramToken(context);
		var missing = [];
		var duplicate = collectTelegramDuplicate(context);
		var hasUnsaved = isTelegramDirty(context);

		if (!username) {
			missing.push({ id: 'telegram_' + context + '_bot_username', label: 'Имя бота' });
		}
		if (!token) {
			missing.push({ id: 'telegram_' + context + '_bot_token', label: 'Токен бота' });
		}
		if (duplicate.has_duplicate) {
			missing = missing.concat(duplicate.fields);
		}

		status.context = context;
		status.missing_fields = missing;
		status.duplicate_fields = duplicate.fields;
		status.has_unsaved_changes = hasUnsaved;
		status.is_configured = !missing.length;
		status.bot_handle = username ? '@' + username : '';

		if (duplicate.has_duplicate) {
			status.invite_ready = false;
			status.operator_ready = false;
			status.blocked_reason = duplicate.message;
		} else if (!status.is_configured) {
			status.invite_ready = false;
			status.operator_ready = false;
			status.blocked_reason = context === 'operator'
				? 'Чтобы включить операторский бот, заполните имя и токен операторского бота.'
				: 'Чтобы включить приглашения мерчантов, заполните имя бота и токен бота.';
		} else if (hasUnsaved) {
			status.invite_ready = false;
			status.operator_ready = false;
			status.blocked_reason = 'Сначала сохраните изменения, затем подключите callback.';
		} else if (!status.webhook_ready) {
			status.invite_ready = false;
			status.operator_ready = false;
			status.blocked_reason = 'Бот ещё не подключён к callback. Сначала нажмите «Подключить callback».';
		} else {
			status.blocked_reason = '';
			if (context === 'operator') {
				status.operator_ready = true;
			} else {
				status.invite_ready = true;
			}
		}

		return status;
	}

	function applyTelegramLockState(status) {
		var context = normalizeTelegramContext(status.context);
		var locked = !!status.webhook_lock;
		var webhookReady = !!status.webhook_ready;
		var canConnect = !locked && !!status.is_configured && !(status.duplicate_fields || []).length && !status.has_unsaved_changes;
		var connectText = webhookReady ? (locked ? 'Callback подключён' : 'Зафиксировать callback') : 'Подключить callback';
		var $form = telegramForm(context);
		var $connectBtn = $form.find('.btn-telegram-connect');
		var $saveBtn = $form.find('.btn-save-telegram-settings');
		$('[data-telegram-context="' + context + '"][data-telegram-field]').prop('readonly', locked).toggleClass('crm-telegram-readonly', locked);
		$form.find('.btn-telegram-unlock').toggleClass('d-none', !locked);
		$saveBtn.prop('disabled', locked).text(locked ? 'Настройки зафиксированы' : 'Сохранить настройки');
		$connectBtn.prop('disabled', !canConnect).text(connectText);
	}

	function renderTelegramStatus(status) {
		var context = normalizeTelegramContext(status && status.context);
		TELEGRAM_STATUSES[context] = $.extend(true, {}, status || {}, { context: context });
		var missingIds = {};
		$.each(TELEGRAM_STATUSES[context].missing_fields || [], function (_, item) {
			if (item && item.id) {
				missingIds[item.id] = true;
			}
		});

		$(telegramSelector(context, 'config_alert')).html(buildTelegramAlertHtml(TELEGRAM_STATUSES[context]));
		renderTelegramStatusLine(TELEGRAM_STATUSES[context]);
		$('[data-telegram-context="' + context + '"][data-telegram-field]').each(function () {
			highlightTelegramField($(this).attr('id'), !!missingIds[$(this).attr('data-telegram-field')]);
		});
		$(telegramSelector(context, 'callback_url')).val(TELEGRAM_STATUSES[context].callback_url || '');
		applyTelegramLockState(TELEGRAM_STATUSES[context]);
	}

	function settingsToast(message, type) {
		if (window.MalibuToast && typeof window.MalibuToast.show === 'function') {
			window.MalibuToast.show(message, type || 'info');
		}
	}

	function handleSettingsForm($form, $alert, extraData, resetLabel) {
		$form.on('submit', function (e) {
			e.preventDefault();
			if ($form.hasClass('telegram-settings-form')) {
				var formTelegramContext = normalizeTelegramContext($form.attr('data-telegram-context'));
				var duplicate = collectTelegramDuplicate(formTelegramContext);
				if (duplicate.has_duplicate) {
					renderTelegramStatus(collectTelegramDraftStatus(formTelegramContext));
					$alert.removeClass('d-none alert-success').addClass('alert-danger').text(duplicate.message);
					settingsToast(duplicate.message, 'danger');
					return;
				}
			}
			var $btn = $(this).find('[type=submit]');
			$btn.prop('disabled', true).text('Сохраняем…');
			$alert.addClass('d-none').removeClass('alert-success alert-danger');

			$.post(AJAX_URL, $.extend({ action: 'me_settings_save', nonce: NONCE }, extraData()))
			.done(function (res) {
				if (res.success) {
					var msg = (res.data && res.data.message) || 'Сохранено';
					$alert.removeClass('d-none alert-danger').addClass('alert-success').text(msg);
					settingsToast(msg, 'success');
					if (res.data.fintech_status) {
						renderFintechStatus(res.data.fintech_status);
					}
					if (res.data.telegram_status) {
						if ($form.hasClass('telegram-settings-form')) {
							markTelegramSaved($form.attr('data-telegram-context'));
						}
						renderTelegramStatus(res.data.telegram_status);
					} else if ($form.is(FINTECH_FORM_SELECTOR)) {
						renderFintechStatus(collectFintechStatus());
					}
				} else {
					var errMsg = (res.data && res.data.message) || 'Ошибка сохранения';
					$alert.removeClass('d-none alert-success').addClass('alert-danger').text(errMsg);
					settingsToast(errMsg, 'danger');
					if (res.data && res.data.telegram_status) {
						renderTelegramStatus(res.data.telegram_status);
					}
				}
			})
			.fail(function () {
				var netMsg = 'Сетевая ошибка. Попробуйте ещё раз.';
				$alert.removeClass('d-none alert-success').addClass('alert-danger').text(netMsg);
				settingsToast(netMsg, 'danger');
			})
			.always(function () {
				if ($form.hasClass('telegram-settings-form')) {
					var alwaysTelegramContext = normalizeTelegramContext($form.attr('data-telegram-context'));
					var latestTelegramStatus = TELEGRAM_STATUSES[alwaysTelegramContext] || collectTelegramDraftStatus(alwaysTelegramContext);
					applyTelegramLockState(latestTelegramStatus);
				} else {
					$btn.prop('disabled', false).text(resetLabel);
				}
			});
		});
	}

	handleSettingsForm(
		$('#system-settings-form'),
		$('#settings-alert'),
		function () { return { section: 'system', timezone: $('#timezone').val() }; },
		'Сохранить'
	);

	$('.telegram-settings-form').each(function () {
		var $telegramForm = $(this);
		var telegramContext = normalizeTelegramContext($telegramForm.attr('data-telegram-context'));
		handleSettingsForm(
			$telegramForm,
			$('#settings-alert'),
			function () {
				return {
					section: 'telegram',
					telegram_context: telegramContext,
					telegram_bot_username: $(telegramSelector(telegramContext, 'bot_username')).val(),
					telegram_bot_token: $(telegramSelector(telegramContext, 'bot_token')).val()
				};
			},
			'Сохранить настройки'
		);
	});

	$('.rates-coefficient-form').each(function () {
		var $form = $(this);
		var pairCode = $form.attr('data-pair-code') || '';
		var $unit = $form.find('.rates-value-unit');
		var $hint = $form.find('.rates-formula-hint');

		function syncTypeUI() {
			var type = $form.find('.rates-type-input:checked').val() || 'absolute';
			$unit.text(type === 'percent' ? $unit.attr('data-unit-percent') : $unit.attr('data-unit-absolute'));
			$hint.text(type === 'percent' ? $hint.attr('data-hint-percent') : $hint.attr('data-hint-absolute'));
		}

		$form.on('change', '.rates-type-input', syncTypeUI);
		syncTypeUI();

		handleSettingsForm(
			$form,
			$('#settings-alert'),
			function () {
				return {
					section:                'rates_coefficient',
					pair_code:              pairCode,
					coefficient_type:       $form.find('.rates-type-input:checked').val() || 'absolute',
					rates_coefficient:      $form.find('.rates-coefficient-input').val()
				};
			},
			'Сохранить'
		);
	});

	$('.rates-source-form').each(function () {
		var $form    = $(this);
		var pairCode = $form.attr('data-pair-code') || '';

		handleSettingsForm(
			$form,
			$('#settings-alert'),
			function () {
				return {
					section:       'rates_market_source',
					pair_code:     pairCode,
					market_source: $form.find('input[type="radio"]:checked').val() || 'bitkub'
				};
			},
			'Сохранить'
		);
	});

	handleSettingsForm(
		$('#merchant-settings-form'),
		$('#settings-alert'),
		function () {
			return {
				section:                           'merchant_settings',
				merchant_invite_ttl_minutes:       $('#merchant_invite_ttl_minutes').val(),
				merchant_default_platform_fee_type: $('#merchant_default_platform_fee_type').val(),
				merchant_default_platform_fee_value: $('#merchant_default_platform_fee_value').val(),
				merchant_bonus_enabled:            $('#merchant_bonus_enabled').is(':checked') ? '1' : '0',
				merchant_referral_enabled:         $('#merchant_referral_enabled').is(':checked') ? '1' : '0',
				merchant_referral_reward_type:     $('#merchant_referral_reward_type').val(),
				merchant_referral_reward_value:    $('#merchant_referral_reward_value').val()
			};
		},
		'Сохранить настройки мерчантов'
	);

	handleSettingsForm(
		$('#fintech-settings-form'),
		$('#settings-alert'),
		function () {
			return {
				section:                       'fintech_general',
				fintech_company_name:          $('#fintech_company_name').val(),
				fintech_merchant_order_prefix: $('#fintech_merchant_order_prefix').val(),
				fintech_active_provider:       $('#fintech_active_provider').val(),
				fintech_debug:                 $('#fintech_debug').is(':checked') ? '1' : '0',
			};
		},
		'Сохранить'
	);

	handleSettingsForm(
		$('#fintech-kanyon-form'),
		$('#settings-alert'),
		function () {
			return {
				section:                         'fintech_kanyon',
				fintech_pay2day_login:           $('#fintech_pay2day_login').val(),
				fintech_pay2day_password:        $('#fintech_pay2day_password').val(),
				fintech_pay2day_tsp_id:          $('#fintech_pay2day_tsp_id').val(),
				fintech_pay2day_order_currency:  $('#fintech_pay2day_order_currency').val(),
				fintech_kanyon_verify_signature: $('#fintech_kanyon_verify_signature').is(':checked') ? '1' : '0',
				fintech_kanyon_public_key_pem:   $('#fintech_kanyon_public_key_pem').val(),
			};
		},
		'Сохранить Kanyon'
	);

	handleSettingsForm(
		$('#fintech-doverka-form'),
		$('#settings-alert'),
		function () {
			return {
				section:                         'fintech_doverka',
				fintech_doverka_api_key:         $('#fintech_doverka_api_key').val(),
				fintech_doverka_currency_id:     $('#fintech_doverka_currency_id').val(),
				fintech_doverka_approve_url:     $('#fintech_doverka_approve_url').val(),
				fintech_doverka_kyc_redirect_url: $('#fintech_doverka_kyc_redirect_url').val(),
			};
		},
		'Сохранить Doverka'
	);

	// Toggle PEM key field visibility
	$('#fintech_kanyon_verify_signature').on('change', function () {
		$('#kanyon-pubkey-row').toggle(this.checked);
		renderFintechStatus(collectFintechStatus());
	});

	function executeTelegramCallbackConnect($btn, telegramContext) {
		$btn.prop('disabled', true).text('Подключаем…');
		$('#settings-alert').addClass('d-none').removeClass('alert-success alert-danger');

		$.post(AJAX_URL, {
			action: 'me_settings_telegram_connect',
			nonce: NONCE,
			telegram_context: telegramContext
		})
		.done(function (res) {
			if (res.success) {
				$('#settings-alert').removeClass('d-none alert-danger').addClass('alert-success').text(res.data.message || 'Telegram callback подключён.');
				if (res.data.telegram_status) {
					renderTelegramStatus(res.data.telegram_status);
				}
			} else {
				$('#settings-alert').removeClass('d-none alert-success').addClass('alert-danger').text((res.data && res.data.message) || 'Не удалось подключить Telegram callback.');
				if (res.data && res.data.telegram_status) {
					renderTelegramStatus(res.data.telegram_status);
				}
			}
		})
		.fail(function () {
			$('#settings-alert').removeClass('d-none alert-success').addClass('alert-danger').text('Сетевая ошибка при подключении Telegram callback.');
		})
		.always(function () {
			renderTelegramStatus(TELEGRAM_STATUSES[telegramContext] || collectTelegramDraftStatus(telegramContext));
		});
	}

	$(document).on('click', '.btn-telegram-connect', function () {
		var $btn = $(this);
		var telegramContext = normalizeTelegramContext($btn.attr('data-telegram-context'));
		if (isTelegramDirty(telegramContext)) {
			var dirtyMessage = 'Сначала сохраните настройки Telegram-бота, затем подключите callback.';
			renderTelegramStatus(collectTelegramDraftStatus(telegramContext));
			$('#settings-alert').removeClass('d-none alert-success').addClass('alert-danger').text(dirtyMessage);
			settingsToast(dirtyMessage, 'danger');
			return;
		}
		var duplicate = collectTelegramDuplicate(telegramContext);
		if (duplicate.has_duplicate) {
			renderTelegramStatus(collectTelegramDraftStatus(telegramContext));
			$('#settings-alert').removeClass('d-none alert-success').addClass('alert-danger').text(duplicate.message);
			settingsToast(duplicate.message, 'danger');
			return;
		}
		var savedUsername = getSavedTelegramUsername(telegramContext);
		var savedToken = getSavedTelegramToken(telegramContext);
		var label = telegramContext === 'operator' ? 'операторского бота' : 'мерчантского бота';
		if (!savedUsername || !savedToken) {
			var missingSavedMessage = 'Сначала сохраните имя и токен Telegram-бота, затем подключите callback.';
			renderTelegramStatus(collectTelegramDraftStatus(telegramContext));
			$('#settings-alert').removeClass('d-none alert-success').addClass('alert-danger').text(missingSavedMessage);
			settingsToast(missingSavedMessage, 'danger');
			return;
		}
		openTelegramCallbackConfirm($btn, telegramContext, savedUsername, savedToken, label);
	});

	$('#telegram-callback-confirm-ok').on('click', function () {
		var pending = pendingTelegramCallbackConnect;
		if (!pending || !pending.button || !pending.button.length) {
			hideTelegramCallbackConfirmModal();
			return;
		}
		pendingTelegramCallbackConnect = null;
		hideTelegramCallbackConfirmModal();
		executeTelegramCallbackConnect(pending.button, pending.context);
	});

	$('#telegram-callback-confirm-modal').on('hidden.bs.modal', function () {
		pendingTelegramCallbackConnect = null;
	});

	$(document).on('click', '.btn-telegram-unlock', function () {
		var $btn = $(this);
		var telegramContext = normalizeTelegramContext($btn.attr('data-telegram-context'));
		$btn.prop('disabled', true).text('Разблокируем…');
		$.post(AJAX_URL, {
			action: 'me_settings_telegram_unlock',
			nonce: NONCE,
			telegram_context: telegramContext
		})
		.done(function (res) {
			if (res.success) {
				$('#settings-alert').removeClass('d-none alert-danger').addClass('alert-success').text(res.data.message || 'Редактирование разблокировано.');
				if (res.data.telegram_status) {
					renderTelegramStatus(res.data.telegram_status);
				}
			} else {
				$('#settings-alert').removeClass('d-none alert-success').addClass('alert-danger').text((res.data && res.data.message) || 'Не удалось разблокировать Telegram-настройки.');
			}
		})
		.fail(function () {
			$('#settings-alert').removeClass('d-none alert-success').addClass('alert-danger').text('Сетевая ошибка при разблокировке Telegram-настроек.');
		})
		.always(function () {
			$btn.prop('disabled', false).text('Разблокировать редактирование');
		});
	});

	$(document).on('input change', '[data-fintech-field], #fintech_active_provider, #fintech_kanyon_verify_signature', function () {
		renderFintechStatus(collectFintechStatus());
	});

	$(document).on('input change', '[data-telegram-field]', function () {
		if (!$(this).prop('readonly')) {
			renderTelegramStatus(collectTelegramDraftStatus($(this).attr('data-telegram-context')));
		}
	});

	renderFintechStatus(collectFintechStatus());
	$.each(TELEGRAM_STATUSES, function (_, status) {
		renderTelegramStatus(status);
	});

}(jQuery));
</script>
<?php
}, 99 );
?>

<?php get_footer(); ?>

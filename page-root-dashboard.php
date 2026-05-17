<?php
/*
Template Name: Root Dashboard Page
Slug: root-dashboard
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

$_current_uid = get_current_user_id();

if ( ! crm_can_access( 'dashboard.view' ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

if ( ! crm_is_root( $_current_uid ) ) {
	wp_safe_redirect( malibu_exchange_get_company_dashboard_url() );
	exit;
}

global $wpdb;

$_tz                   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
$_now_local            = new DateTime( 'now', $_tz );
$_recent_users_from    = ( clone $_now_local )->modify( '-6 days' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
$_recent_companies_from = ( clone $_now_local )->modify( '-29 days' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
$_root_companies = $wpdb->get_results(
	"SELECT
		c.id,
		c.code,
		c.name,
		c.status,
		c.created_at,
		(
			SELECT COUNT(*)
			FROM crm_user_companies uc
			WHERE uc.company_id = c.id AND uc.is_primary = 1 AND uc.status = 'active'
		) AS users_cnt,
		(
			SELECT COUNT(*)
			FROM crm_fintech_payment_orders o
			WHERE o.company_id = c.id
		) AS orders_cnt,
		(
			SELECT COALESCE(SUM(o.amount_asset_value), 0)
			FROM crm_fintech_payment_orders o
			WHERE o.company_id = c.id AND o.status_code = 'paid'
		) AS paid_sum,
		(
			SELECT COALESCE(SUM(p.amount), 0)
			FROM crm_acquirer_payouts p
			WHERE p.company_id = c.id
		) AS payouts_sum,
		(
			SELECT MAX(o.created_at)
			FROM crm_fintech_payment_orders o
			WHERE o.company_id = c.id
		) AS last_order_at
	FROM crm_companies c
	WHERE c.id > 0
	ORDER BY FIELD(c.status, 'active', 'blocked', 'archived'), c.id ASC"
) ?: [];

$_root_status_stats = $wpdb->get_row(
	"SELECT
		COALESCE(SUM(CASE WHEN status_code IN ('created', 'pending') THEN 1 ELSE 0 END), 0) AS open_cnt,
		COALESCE(SUM(CASE WHEN status_code IN ('created', 'pending') THEN amount_asset_value ELSE 0 END), 0) AS open_sum,
		COALESCE(SUM(CASE WHEN status_code = 'paid' THEN 1 ELSE 0 END), 0) AS closed_cnt,
		COALESCE(SUM(CASE WHEN status_code = 'paid' THEN amount_asset_value ELSE 0 END), 0) AS closed_sum,
		COALESCE(SUM(CASE WHEN status_code IN ('declined', 'cancelled', 'expired', 'error') THEN 1 ELSE 0 END), 0) AS cancel_cnt,
		COALESCE(SUM(CASE WHEN status_code IN ('declined', 'cancelled', 'expired', 'error') THEN amount_asset_value ELSE 0 END), 0) AS cancel_sum
	FROM crm_fintech_payment_orders
	WHERE company_id > 0"
);

$_root_new_users_7d = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(DISTINCT uc.user_id)
	FROM {$wpdb->users} u
	INNER JOIN crm_user_companies uc
		ON uc.user_id = u.ID
		AND uc.is_primary = 1
		AND uc.status = 'active'
	WHERE u.ID != 1
		AND uc.company_id > 0
		AND u.user_registered >= %s",
	$_recent_users_from
) );

$_root_new_companies_30d = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*)
	FROM crm_companies
	WHERE id > 0
		AND created_at >= %s",
	$_recent_companies_from
) );

$_root_recent_users = $wpdb->get_results(
	"SELECT
		u.ID,
		u.user_login,
		u.display_name,
		u.user_registered,
		c.id AS company_id,
		c.name AS company_name
	FROM {$wpdb->users} u
	INNER JOIN crm_user_companies uc
		ON uc.user_id = u.ID
		AND uc.is_primary = 1
		AND uc.status = 'active'
	INNER JOIN crm_companies c
		ON c.id = uc.company_id
	WHERE u.ID != 1
		AND c.id > 0
	ORDER BY u.user_registered DESC
	LIMIT 8"
) ?: [];

$_root_recent_payouts = $wpdb->get_results(
	"SELECT
		p.id,
		p.amount,
		p.created_at,
		c.id AS company_id,
		c.name AS company_name
	FROM crm_acquirer_payouts p
	LEFT JOIN crm_companies c
		ON c.id = p.company_id
	WHERE p.company_id > 0
	ORDER BY p.created_at DESC
	LIMIT 8"
) ?: [];

$_root_has_merchant_payouts_table = function_exists( 'malibu_migrations_table_exists' )
	? malibu_migrations_table_exists( 'crm_merchant_payouts' )
	: (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', 'crm_merchant_payouts' ) );
$_root_has_company_withdrawals_table = function_exists( 'malibu_migrations_table_exists' )
	? malibu_migrations_table_exists( 'crm_company_withdrawals' )
	: (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', 'crm_company_withdrawals' ) );

$_root_payment_contour_rows = $wpdb->get_results(
	"SELECT
		company_id,
		COALESCE(SUM(CASE WHEN status_code = 'paid' AND created_for_type = 'merchant' THEN amount_asset_value ELSE 0 END), 0) AS merchant_paid_sum,
		COALESCE(SUM(CASE WHEN status_code = 'paid' AND created_for_type = 'company' THEN amount_asset_value ELSE 0 END), 0) AS company_paid_sum,
		COALESCE(SUM(CASE WHEN status_code = 'paid' AND source_channel = 'telegram_operator' THEN amount_asset_value ELSE 0 END), 0) AS operator_tg_paid_sum,
		COALESCE(SUM(CASE WHEN status_code = 'paid' AND source_channel = 'web' AND created_for_type = 'company' THEN amount_asset_value ELSE 0 END), 0) AS company_web_paid_sum,
		COALESCE(SUM(CASE WHEN status_code = 'paid' AND created_for_type = 'merchant' THEN COALESCE(platform_fee_value, merchant_markup_value, 0) ELSE 0 END), 0) AS platform_fee_sum
	 FROM crm_fintech_payment_orders
	 WHERE company_id > 0
	 GROUP BY company_id"
) ?: [];

$_root_merchant_balance_rows = $wpdb->get_results(
	"SELECT
		company_id,
		COALESCE(SUM(CASE
			WHEN entry_type IN ('merchant_accrual', 'manual_credit', 'bonus_accrual', 'bonus_adjustment', 'referral_accrual', 'referral_adjustment')
				THEN amount
			WHEN entry_type IN ('merchant_payout', 'manual_debit', 'bonus_withdrawal')
				THEN -ABS(amount)
			ELSE 0
		END), 0) AS merchant_payable_sum
	 FROM crm_merchant_wallet_ledger
	 WHERE company_id > 0
	 GROUP BY company_id"
) ?: [];

$_root_merchant_payout_rows = $_root_has_merchant_payouts_table
	? ( $wpdb->get_results(
		"SELECT company_id, COALESCE(SUM(amount), 0) AS merchant_payouts_sum
		 FROM crm_merchant_payouts
		 WHERE company_id > 0
		 GROUP BY company_id"
	) ?: [] )
	: [];

$_root_company_withdrawal_rows = $_root_has_company_withdrawals_table
	? ( $wpdb->get_results(
		"SELECT company_id, COALESCE(SUM(amount_usdt), 0) AS withdrawals_sum
		 FROM crm_company_withdrawals
		 WHERE company_id > 0
		 GROUP BY company_id"
	) ?: [] )
	: [];

$_root_provider_paid_rows = $wpdb->get_results(
	"SELECT
		COALESCE(NULLIF(provider_code, ''), 'unknown') AS provider_code,
		COUNT(*) AS orders_cnt,
		COALESCE(SUM(amount_asset_value), 0) AS paid_sum
	 FROM crm_fintech_payment_orders
	 WHERE company_id > 0
	   AND status_code = 'paid'
	 GROUP BY COALESCE(NULLIF(provider_code, ''), 'unknown')"
) ?: [];

$_root_provider_payout_rows = $wpdb->get_results(
	"SELECT
		COALESCE(NULLIF(provider_code, ''), 'unknown') AS provider_code,
		COALESCE(SUM(amount), 0) AS payouts_sum
	 FROM crm_acquirer_payouts
	 WHERE company_id > 0
	 GROUP BY COALESCE(NULLIF(provider_code, ''), 'unknown')"
) ?: [];
// phpcs:enable

$_root_company_total        = count( $_root_companies );
$_root_company_active_count = 0;
$_root_users_total          = 0;
$_root_orders_total         = 0;
$_root_paid_total           = 0.0;
$_root_payout_total         = 0.0;
$_root_merchant_paid_total  = 0.0;
$_root_company_paid_total   = 0.0;
$_root_merchant_payable_total = 0.0;
$_root_merchant_payout_total  = 0.0;
$_root_platform_fee_total     = 0.0;
$_root_company_withdrawal_total = 0.0;
$_root_profit_balance_total     = 0.0;

$_root_payment_contour_map = [];
foreach ( $_root_payment_contour_rows as $_row ) {
	$_root_payment_contour_map[ (int) $_row->company_id ] = $_row;
}

$_root_merchant_payable_map = [];
foreach ( $_root_merchant_balance_rows as $_row ) {
	$_root_merchant_payable_map[ (int) $_row->company_id ] = (float) $_row->merchant_payable_sum;
}

$_root_merchant_payout_map = [];
foreach ( $_root_merchant_payout_rows as $_row ) {
	$_root_merchant_payout_map[ (int) $_row->company_id ] = (float) $_row->merchant_payouts_sum;
}

$_root_company_withdrawal_map = [];
foreach ( $_root_company_withdrawal_rows as $_row ) {
	$_root_company_withdrawal_map[ (int) $_row->company_id ] = (float) $_row->withdrawals_sum;
}

$_root_provider_labels = function_exists( 'crm_fintech_provider_labels' ) ? crm_fintech_provider_labels() : [
	'kanyon'  => 'Kanyon (Pay2Day)',
	'doverka' => 'Doverka',
];
$_root_provider_map = [];
foreach ( $_root_provider_paid_rows as $_row ) {
	$_provider_code = (string) $_row->provider_code;
	$_root_provider_map[ $_provider_code ] = [
		'provider_code' => $_provider_code,
		'orders_cnt'    => (int) $_row->orders_cnt,
		'paid_sum'      => (float) $_row->paid_sum,
		'payouts_sum'   => 0.0,
	];
}
foreach ( $_root_provider_payout_rows as $_row ) {
	$_provider_code = (string) $_row->provider_code;
	if ( ! isset( $_root_provider_map[ $_provider_code ] ) ) {
		$_root_provider_map[ $_provider_code ] = [
			'provider_code' => $_provider_code,
			'orders_cnt'    => 0,
			'paid_sum'      => 0.0,
			'payouts_sum'   => 0.0,
		];
	}
	$_root_provider_map[ $_provider_code ]['payouts_sum'] = (float) $_row->payouts_sum;
}
ksort( $_root_provider_map );

foreach ( $_root_companies as $_root_company ) {
	if ( $_root_company->status === 'active' ) {
		$_root_company_active_count++;
	}

	$_company_id = (int) $_root_company->id;
	$_contour    = $_root_payment_contour_map[ $_company_id ] ?? null;
	$_root_company->merchant_paid_sum     = $contour_merchant_paid = (float) ( $_contour->merchant_paid_sum ?? 0 );
	$_root_company->company_paid_sum      = $contour_company_paid = (float) ( $_contour->company_paid_sum ?? 0 );
	$_root_company->operator_tg_paid_sum  = (float) ( $_contour->operator_tg_paid_sum ?? 0 );
	$_root_company->company_web_paid_sum  = (float) ( $_contour->company_web_paid_sum ?? 0 );
	$_root_company->platform_fee_sum      = $platform_fee_sum = (float) ( $_contour->platform_fee_sum ?? 0 );
	$_root_company->merchant_payable_sum  = $merchant_payable_sum = max( 0.0, (float) ( $_root_merchant_payable_map[ $_company_id ] ?? 0 ) );
	$_root_company->merchant_payouts_sum  = $merchant_payouts_sum = (float) ( $_root_merchant_payout_map[ $_company_id ] ?? 0 );
	$_root_company->withdrawals_sum       = $withdrawals_sum = (float) ( $_root_company_withdrawal_map[ $_company_id ] ?? 0 );
	$_root_company->profit_balance_sum    = $platform_fee_sum - $withdrawals_sum;

	$_root_users_total  += (int) $_root_company->users_cnt;
	$_root_orders_total += (int) $_root_company->orders_cnt;
	$_root_paid_total   += (float) $_root_company->paid_sum;
	$_root_payout_total += (float) $_root_company->payouts_sum;
	$_root_merchant_paid_total      += $contour_merchant_paid;
	$_root_company_paid_total       += $contour_company_paid;
	$_root_merchant_payable_total   += $merchant_payable_sum;
	$_root_merchant_payout_total    += $merchant_payouts_sum;
	$_root_platform_fee_total       += $platform_fee_sum;
	$_root_company_withdrawal_total += $withdrawals_sum;
	$_root_profit_balance_total     += (float) $_root_company->profit_balance_sum;
}

$_root_debt_total = $_root_paid_total - $_root_payout_total;
$_root_open_cnt   = (int) ( $_root_status_stats->open_cnt ?? 0 );
$_root_open_sum   = (float) ( $_root_status_stats->open_sum ?? 0 );
$_root_closed_cnt = (int) ( $_root_status_stats->closed_cnt ?? 0 );
$_root_closed_sum = (float) ( $_root_status_stats->closed_sum ?? 0 );
$_root_cancel_cnt = (int) ( $_root_status_stats->cancel_cnt ?? 0 );
$_root_cancel_sum = (float) ( $_root_status_stats->cancel_sum ?? 0 );

function _root_dash_fmt_usdt( float $value ): string {
	$formatted = number_format( $value, 8, '.', "\xc2\xa0" );

	if ( strpos( $formatted, '.' ) === false ) {
		return $formatted . '.00' . "\xc2\xa0USDT";
	}

	list( $integer, $fraction ) = explode( '.', $formatted, 2 );
	$fraction = rtrim( $fraction, '0' );

	if ( $fraction === '' ) {
		$fraction = '00';
	} elseif ( strlen( $fraction ) < 2 ) {
		$fraction = str_pad( $fraction, 2, '0' );
	}

	return $integer . '.' . $fraction . "\xc2\xa0USDT";
}

$_root_company_status_map = [
	'active'   => [ 'label' => 'Активна', 'class' => 'text-success' ],
	'blocked'  => [ 'label' => 'Заблокирована', 'class' => 'text-warning' ],
	'archived' => [ 'label' => 'Архив', 'class' => 'text-master' ],
];

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
							<li class="breadcrumb-item active">Сводка</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">
				<div class="row m-b-5">
					<div class="col-12">
						<p class="hint-text fs-12 text-uppercase m-b-10" style="letter-spacing:.07em;">
							Сводка root по всем бизнес-компаниям — <?php echo esc_html( $_now_local->format( 'd.m.Y H:i' ) ); ?>
							<span class="m-l-10 text-muted" style="letter-spacing:0;"><?php echo esc_html( $_tz->getName() ); ?></span>
						</p>
						<p class="hint-text m-b-20">
							Отдельный контур только для root. Обычный <strong>dashboard</strong> остаётся company-scoped: для обычного пользователя он показывает его компанию, для root — его системный контур <strong>0</strong>. Этот обзор в агрегаты системную компанию <strong>0</strong> не включает.
						</p>
					</div>
				</div>

				<div class="row m-b-20">
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Активных компаний</p>
								<h3 class="no-margin bold text-master"><?php echo esc_html( $_root_company_active_count ); ?></h3>
								<p class="hint-text fs-11 no-margin">всего компаний: <?php echo esc_html( $_root_company_total ); ?></p>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Пользователей</p>
								<h3 class="no-margin bold text-complete"><?php echo esc_html( $_root_users_total ); ?></h3>
								<p class="hint-text fs-11 no-margin">активные primary-привязки</p>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Сделок всего</p>
								<h3 class="no-margin bold text-primary"><?php echo esc_html( $_root_orders_total ); ?></h3>
								<p class="hint-text fs-11 no-margin">по всем компаниям</p>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Общий долг ЭП</p>
								<h3 class="no-margin bold font-montserrat <?php echo $_root_debt_total >= 0 ? 'text-warning' : 'text-success'; ?>">
									<?php echo esc_html( _root_dash_fmt_usdt( $_root_debt_total ) ); ?>
								</h3>
								<p class="hint-text fs-11 no-margin">paid минус выплаты</p>
							</div>
						</div>
					</div>
				</div>

				<div class="row m-b-20">
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Paid по всем компаниям</p>
								<h3 class="no-margin bold text-success font-montserrat"><?php echo esc_html( _root_dash_fmt_usdt( $_root_paid_total ) ); ?></h3>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Выплаты ЭП</p>
								<h3 class="no-margin bold text-complete font-montserrat"><?php echo esc_html( _root_dash_fmt_usdt( $_root_payout_total ) ); ?></h3>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Новых пользователей 7д</p>
								<h3 class="no-margin bold text-primary"><?php echo esc_html( $_root_new_users_7d ); ?></h3>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Новых компаний 30д</p>
								<h3 class="no-margin bold text-info"><?php echo esc_html( $_root_new_companies_30d ); ?></h3>
							</div>
						</div>
					</div>
				</div>

				<div class="row m-b-20">
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Merchant paid</p>
								<h3 class="no-margin bold text-complete font-montserrat"><?php echo esc_html( _root_dash_fmt_usdt( $_root_merchant_paid_total ) ); ?></h3>
								<p class="hint-text fs-11 no-margin">gross paid-объём мерчантов</p>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Operator / company paid</p>
								<h3 class="no-margin bold text-primary font-montserrat"><?php echo esc_html( _root_dash_fmt_usdt( $_root_company_paid_total ) ); ?></h3>
								<p class="hint-text fs-11 no-margin">ордера без мерчантского контура</p>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Долг мерчантам</p>
								<h3 class="no-margin bold text-warning font-montserrat"><?php echo esc_html( _root_dash_fmt_usdt( $_root_merchant_payable_total ) ); ?></h3>
								<p class="hint-text fs-11 no-margin">основной + бонус + рефка</p>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Profit / wallet</p>
								<h3 class="no-margin bold font-montserrat <?php echo $_root_profit_balance_total < 0 ? 'text-danger' : 'text-success'; ?>">
									<?php echo esc_html( _root_dash_fmt_usdt( $_root_profit_balance_total ) ); ?>
								</h3>
								<p class="hint-text fs-11 no-margin">
									fee: <?php echo esc_html( _root_dash_fmt_usdt( $_root_platform_fee_total ) ); ?> · выводы: <?php echo esc_html( _root_dash_fmt_usdt( $_root_company_withdrawal_total ) ); ?>
								</p>
							</div>
						</div>
					</div>
				</div>

				<div class="row m-b-20">
					<div class="col-xl-4 col-lg-4 col-md-4 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<div class="d-flex align-items-center m-b-5">
									<i class="pg-icon text-warning m-r-10" style="font-size:22px;">time</i>
									<span class="hint-text fs-12 text-uppercase">Открытые сделки</span>
								</div>
								<h4 class="no-margin bold text-warning font-montserrat"><?php echo esc_html( _root_dash_fmt_usdt( $_root_open_sum ) ); ?></h4>
								<p class="hint-text fs-11 no-margin">created + pending · <?php echo esc_html( $_root_open_cnt ); ?> шт.</p>
							</div>
						</div>
					</div>
					<div class="col-xl-4 col-lg-4 col-md-4 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<div class="d-flex align-items-center m-b-5">
									<i class="pg-icon text-success m-r-10" style="font-size:22px;">tick_circle</i>
									<span class="hint-text fs-12 text-uppercase">Закрытые сделки</span>
								</div>
								<h4 class="no-margin bold text-success font-montserrat"><?php echo esc_html( _root_dash_fmt_usdt( $_root_closed_sum ) ); ?></h4>
								<p class="hint-text fs-11 no-margin">paid · <?php echo esc_html( $_root_closed_cnt ); ?> шт.</p>
							</div>
						</div>
					</div>
					<div class="col-xl-4 col-lg-4 col-md-4 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<div class="d-flex align-items-center m-b-5">
									<i class="pg-icon text-danger m-r-10" style="font-size:22px;">close</i>
									<span class="hint-text fs-12 text-uppercase">Отменённые / ошибочные</span>
								</div>
								<h4 class="no-margin bold text-danger font-montserrat"><?php echo esc_html( _root_dash_fmt_usdt( $_root_cancel_sum ) ); ?></h4>
								<p class="hint-text fs-11 no-margin">declined + cancelled + expired + error · <?php echo esc_html( $_root_cancel_cnt ); ?> шт.</p>
							</div>
						</div>
					</div>
				</div>

				<div class="row m-b-20">
					<div class="col-lg-12 col-md-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-header">
								<div class="card-title">Провайдеры</div>
							</div>
							<div class="card-body">
								<div class="table-responsive">
									<table class="table table-hover m-b-0">
										<thead>
											<tr>
												<th>Провайдер</th>
												<th>Paid-ордеры</th>
												<th>Paid сумма</th>
												<th>Выплаты ЭП</th>
												<th>Долг ЭП</th>
											</tr>
										</thead>
										<tbody>
											<?php if ( empty( $_root_provider_map ) ) : ?>
											<tr>
												<td colspan="5" class="text-center hint-text p-t-20 p-b-20">Paid-ордеров по провайдерам пока нет.</td>
											</tr>
											<?php else : ?>
												<?php foreach ( $_root_provider_map as $_root_provider ) : ?>
													<?php
													$_provider_code  = (string) $_root_provider['provider_code'];
													$_provider_label = $_root_provider_labels[ $_provider_code ] ?? ( $_provider_code === 'unknown' ? 'Не указан' : $_provider_code );
													$_provider_debt  = (float) $_root_provider['paid_sum'] - (float) $_root_provider['payouts_sum'];
													?>
													<tr>
														<td>
															<div class="bold"><?php echo esc_html( $_provider_label ); ?></div>
															<div class="hint-text fs-11"><?php echo esc_html( $_provider_code ); ?></div>
														</td>
														<td><?php echo esc_html( (int) $_root_provider['orders_cnt'] ); ?></td>
														<td class="text-success"><?php echo esc_html( _root_dash_fmt_usdt( (float) $_root_provider['paid_sum'] ) ); ?></td>
														<td class="text-complete"><?php echo esc_html( _root_dash_fmt_usdt( (float) $_root_provider['payouts_sum'] ) ); ?></td>
														<td class="<?php echo esc_attr( $_provider_debt >= 0 ? 'text-warning' : 'text-success' ); ?>">
															<?php echo esc_html( _root_dash_fmt_usdt( $_provider_debt ) ); ?>
														</td>
													</tr>
												<?php endforeach; ?>
											<?php endif; ?>
										</tbody>
									</table>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div class="card card-default m-b-20">
					<div class="card-header">
						<div class="card-title">Финансовая картина компаний</div>
					</div>
					<div class="card-body">
						<div class="table-responsive">
							<table class="table table-hover m-b-0">
								<thead>
									<tr>
										<th>Компания</th>
										<th>Merchant paid</th>
										<th>Operator / company paid</th>
										<th>Долг ЭП</th>
										<th>Долг мерчантам</th>
										<th>Выплачено мерчантам</th>
										<th>Profit / wallet</th>
										<th>Выведено</th>
									</tr>
								</thead>
								<tbody>
									<?php if ( empty( $_root_companies ) ) : ?>
									<tr>
										<td colspan="8" class="text-center hint-text p-t-20 p-b-20">Бизнес-компаний пока нет.</td>
									</tr>
									<?php else : ?>
										<?php foreach ( $_root_companies as $_root_company ) : ?>
											<?php
											$_company_debt   = (float) $_root_company->paid_sum - (float) $_root_company->payouts_sum;
											$_company_profit = (float) $_root_company->profit_balance_sum;
											?>
											<tr>
												<td>
													<div class="bold"><?php echo esc_html( $_root_company->name ); ?></div>
													<div class="hint-text fs-11">#<?php echo esc_html( (int) $_root_company->id ); ?> · <?php echo esc_html( $_root_company->code ); ?></div>
												</td>
												<td class="text-complete"><?php echo esc_html( _root_dash_fmt_usdt( (float) $_root_company->merchant_paid_sum ) ); ?></td>
												<td class="text-primary">
													<?php echo esc_html( _root_dash_fmt_usdt( (float) $_root_company->company_paid_sum ) ); ?>
													<div class="hint-text fs-11">TG: <?php echo esc_html( _root_dash_fmt_usdt( (float) $_root_company->operator_tg_paid_sum ) ); ?></div>
												</td>
												<td class="<?php echo esc_attr( $_company_debt >= 0 ? 'text-warning' : 'text-success' ); ?>"><?php echo esc_html( _root_dash_fmt_usdt( $_company_debt ) ); ?></td>
												<td class="text-warning"><?php echo esc_html( _root_dash_fmt_usdt( (float) $_root_company->merchant_payable_sum ) ); ?></td>
												<td class="text-complete"><?php echo esc_html( _root_dash_fmt_usdt( (float) $_root_company->merchant_payouts_sum ) ); ?></td>
												<td class="<?php echo esc_attr( $_company_profit < 0 ? 'text-danger' : 'text-success' ); ?>">
													<?php echo esc_html( _root_dash_fmt_usdt( $_company_profit ) ); ?>
													<div class="hint-text fs-11">fee: <?php echo esc_html( _root_dash_fmt_usdt( (float) $_root_company->platform_fee_sum ) ); ?></div>
												</td>
												<td class="text-complete"><?php echo esc_html( _root_dash_fmt_usdt( (float) $_root_company->withdrawals_sum ) ); ?></td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<div class="card card-default m-b-20">
					<div class="card-header">
						<div class="card-title">Компании</div>
					</div>
					<div class="card-body">
						<div class="table-responsive">
							<table class="table table-hover m-b-0">
								<thead>
									<tr>
										<th>#</th>
										<th>Статус</th>
										<th>Компания</th>
										<th>Пользователи</th>
										<th>Ордера</th>
										<th>Paid</th>
										<th>Выплаты</th>
										<th>Долг ЭП</th>
										<th>Последний ордер</th>
										<th>Создана</th>
									</tr>
								</thead>
								<tbody>
									<?php if ( empty( $_root_companies ) ) : ?>
									<tr>
										<td colspan="10" class="text-center hint-text p-t-20 p-b-20">Бизнес-компаний пока нет.</td>
									</tr>
									<?php else : ?>
										<?php foreach ( $_root_companies as $_root_company ) : ?>
											<?php
											$_company_debt   = (float) $_root_company->paid_sum - (float) $_root_company->payouts_sum;
											$_last_order     = ! empty( $_root_company->last_order_at ) ? wp_date( 'd.m.Y H:i', strtotime( (string) $_root_company->last_order_at ), $_tz ) : '—';
											$_company_created = ! empty( $_root_company->created_at ) ? wp_date( 'd.m.Y H:i', strtotime( (string) $_root_company->created_at ), $_tz ) : '—';
											$_status_meta    = $_root_company_status_map[ $_root_company->status ] ?? [ 'label' => (string) $_root_company->status, 'class' => 'text-master' ];
											?>
											<tr>
												<td><?php echo esc_html( (int) $_root_company->id ); ?></td>
												<td><span class="<?php echo esc_attr( $_status_meta['class'] ); ?>"><?php echo esc_html( $_status_meta['label'] ); ?></span></td>
												<td>
													<div class="bold"><?php echo esc_html( $_root_company->name ); ?></div>
													<div class="hint-text fs-11"><?php echo esc_html( $_root_company->code ); ?></div>
												</td>
												<td><?php echo esc_html( (int) $_root_company->users_cnt ); ?></td>
												<td><?php echo esc_html( (int) $_root_company->orders_cnt ); ?></td>
												<td class="text-success"><?php echo esc_html( _root_dash_fmt_usdt( (float) $_root_company->paid_sum ) ); ?></td>
												<td class="text-complete"><?php echo esc_html( _root_dash_fmt_usdt( (float) $_root_company->payouts_sum ) ); ?></td>
												<td class="<?php echo esc_attr( $_company_debt >= 0 ? 'text-warning' : 'text-success' ); ?>"><?php echo esc_html( _root_dash_fmt_usdt( $_company_debt ) ); ?></td>
												<td><?php echo esc_html( $_last_order ); ?></td>
												<td><?php echo esc_html( $_company_created ); ?></td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<div class="row m-b-20">
					<div class="col-lg-6 col-md-12 m-b-15">
						<div class="card card-default no-margin full-height">
							<div class="card-header">
								<div class="card-title">Последние созданные пользователи</div>
							</div>
							<div class="card-body">
								<div class="table-responsive">
									<table class="table table-hover m-b-0">
										<thead>
											<tr>
												<th>Пользователь</th>
												<th>Компания</th>
												<th>Создан</th>
											</tr>
										</thead>
										<tbody>
											<?php if ( empty( $_root_recent_users ) ) : ?>
											<tr>
												<td colspan="3" class="text-center hint-text p-t-20 p-b-20">Созданных пользователей пока нет.</td>
											</tr>
											<?php else : ?>
												<?php foreach ( $_root_recent_users as $_root_user ) : ?>
												<tr>
													<td>
														<div class="bold"><?php echo esc_html( $_root_user->display_name !== '' ? $_root_user->display_name : $_root_user->user_login ); ?></div>
														<div class="hint-text fs-11"><?php echo esc_html( $_root_user->user_login ); ?></div>
													</td>
													<td><?php echo esc_html( $_root_user->company_name ); ?></td>
													<td><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( (string) $_root_user->user_registered ), $_tz ) ); ?></td>
												</tr>
												<?php endforeach; ?>
											<?php endif; ?>
										</tbody>
									</table>
								</div>
							</div>
						</div>
					</div>
					<div class="col-lg-6 col-md-12 m-b-15">
						<div class="card card-default no-margin full-height">
							<div class="card-header">
								<div class="card-title">Последние выплаты ЭП</div>
							</div>
							<div class="card-body">
								<div class="table-responsive">
									<table class="table table-hover m-b-0">
										<thead>
											<tr>
												<th>ID</th>
												<th>Компания</th>
												<th>Сумма</th>
												<th>Создана</th>
											</tr>
										</thead>
										<tbody>
											<?php if ( empty( $_root_recent_payouts ) ) : ?>
											<tr>
												<td colspan="4" class="text-center hint-text p-t-20 p-b-20">Выплат пока нет.</td>
											</tr>
											<?php else : ?>
												<?php foreach ( $_root_recent_payouts as $_root_payout ) : ?>
												<tr>
													<td><?php echo esc_html( (int) $_root_payout->id ); ?></td>
													<td><?php echo esc_html( $_root_payout->company_name ?: '—' ); ?></td>
													<td class="text-complete"><?php echo esc_html( _root_dash_fmt_usdt( (float) $_root_payout->amount ) ); ?></td>
													<td><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( (string) $_root_payout->created_at ), $_tz ) ); ?></td>
												</tr>
												<?php endforeach; ?>
											<?php endif; ?>
										</tbody>
									</table>
								</div>
							</div>
						</div>
					</div>
				</div>

			</div>
		</div>

		<?php get_template_part( 'template-parts/footer-backoffice' ); ?>
	</div>
</div>

<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>
<?php get_footer(); ?>

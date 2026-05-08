<?php
/*
Template Name: Rates Page
Slug: rates
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

if ( ! crm_user_has_permission( get_current_user_id(), 'rates.view' ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

// ─── Данные пары и коэффициента ──────────────────────────────────────────────
$rates_org_id = crm_require_company_page_context();
$pair         = rates_get_pair( RATES_PAIR_CODE, $rates_org_id );
$coeff_full   = $pair
	? rates_get_coefficient_full( (int) $pair->id, RATES_PROVIDER_EX24, RATES_PROVIDER_SOURCE )
	: [ 'value' => 0.05, 'type' => 'absolute' ];
$coeff        = (float) $coeff_full['value'];
$coeff_type   = (string) $coeff_full['type'];

// Текущие курсы Ex24 — только для отображения, кэш 5 мин, в базу не пишем
$ex24      = rates_get_ex24_cached( RATES_PROVIDER_SOURCE );
$ex24_ok   = $ex24['ok'];
$ex24_err  = $ex24['error'] ?? '';

$comp_sber    = $ex24['sberbank_buy'];
$comp_tinkoff = $ex24['tinkoff_buy'];
$calculated   = rates_calculate( $comp_sber, $comp_tinkoff, $coeff, $coeff_type );
$our_sber     = $calculated['our_sberbank'];
$our_tinkoff  = $calculated['our_tinkoff'];

// ─── История из базы ─────────────────────────────────────────────────────────
$history = $pair ? rates_get_history( (int) $pair->id, 75, $rates_org_id ) : [];

// ─── Данные для графика (хронологический порядок) ────────────────────────────
$chart_history = array_reverse( $history );
$chart_sber    = [];
$chart_tinkoff = [];
foreach ( $chart_history as $row ) {
	$ts = strtotime( $row['created_at'] ) * 1000; // JS timestamp в миллисекундах
	if ( $row['our_sberbank_rate'] !== null ) {
		$chart_sber[] = [ $ts, (float) $row['our_sberbank_rate'] ];
	}
	if ( $row['our_tinkoff_rate'] !== null ) {
		$chart_tinkoff[] = [ $ts, (float) $row['our_tinkoff_rate'] ];
	}
}

// ─── Рыночные курсы (кэш 3 мин, только для отображения) ──────────────────────
$rapira     = rates_get_rapira_cached();
$bitkub     = rates_get_bitkub_cached();
$binance_th = rates_get_binance_th_cached();
$cbr        = rates_get_cbr_usd_cached();

// ─── Последние сохранённые снимки из crm_market_snapshots_usdt ───────────────
$last_rapira     = rates_get_last_market_snapshot( 'rapira', $rates_org_id );
$last_bitkub     = rates_get_last_market_snapshot( 'bitkub', $rates_org_id );
$last_binance_th = rates_get_last_market_snapshot( 'binance_th', $rates_org_id );

// ─── История снимков для таблицы ─────────────────────────────────────────────
$market_history = rates_get_all_market_history( 100, $rates_org_id );

// ─── Заголовок: энкодируем для JS ────────────────────────────────────────────
$nonce_save        = wp_create_nonce( 'me_rates_save' );
$nonce_market_save = wp_create_nonce( 'me_market_snapshot_save' );

// ─── Kanyon USDT/RUB ─────────────────────────────────────────────────────────
$kanyon_allowed  = rates_kanyon_is_enabled_for_company( $rates_org_id );
$last_kanyon     = $kanyon_allowed ? rates_kanyon_get_last( $rates_org_id ) : null;
$kanyon_history  = $kanyon_allowed ? rates_kanyon_get_history( $rates_org_id, 50 ) : [];
$kanyon_cooldown = $kanyon_allowed ? rates_kanyon_cooldown_remaining( $rates_org_id ) : 0;
$nonce_kanyon    = $kanyon_allowed ? wp_create_nonce( 'me_kanyon_rate' ) : '';

$vendor_uri     = get_template_directory_uri() . '/vendor/pages/assets/plugins';

// ─── Подключить CSS и JS для DataTables и NVD3 ───────────────────────────────
wp_enqueue_style( 'rates-dt-css',   $vendor_uri . '/jquery-datatable/media/css/dataTables.bootstrap.min.css', [], null );
wp_enqueue_style( 'rates-nvd3-css', $vendor_uri . '/nvd3/nv.d3.min.css', [], null );

wp_enqueue_script( 'rates-datatable',    $vendor_uri . '/jquery-datatable/media/js/jquery.dataTables.min.js',    [ 'jquery' ], null, true );
wp_enqueue_script( 'rates-dt-bootstrap', $vendor_uri . '/jquery-datatable/media/js/dataTables.bootstrap.min.js', [ 'rates-datatable' ], null, true );
wp_enqueue_script( 'rates-d3',           $vendor_uri . '/d3/d3.min.js',          [], null, true );
wp_enqueue_script( 'rates-nvd3',         $vendor_uri . '/nvd3/nv.d3.min.js',     [ 'rates-d3' ], null, true );

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
							<li class="breadcrumb-item active">Курсы</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4 rates-page">

				<?php if ( ! $ex24_ok ) : ?>
				<!-- Алерт: Ex24 недоступен -->
				<div class="alert alert-danger m-b-20" role="alert">
					<strong>Ex24 недоступен.</strong>
					<?php echo esc_html( $ex24_err ); ?>
					История курсов ниже остаётся доступной.
				</div>
				<?php endif; ?>

				<?php if ( ! $pair ) : ?>
				<!-- Empty state: пара не активирована для текущей компании -->
				<div class="alert alert-warning m-b-20" role="alert">
					<strong>Курсы для вашей компании пока не настроены.</strong>
					Активная валютная пара отсутствует — обратитесь к администратору, чтобы он включил пару и задал коэффициент.
				</div>
				<?php endif; ?>

				<!-- Алерт результата сохранения -->
				<div id="rates-save-alert" class="alert d-none m-b-20" role="alert"></div>

				<!-- ─── Карточки текущих курсов ─────────────────────────────────── -->

				<!-- Последние сохранённые рыночные курсы (из crm_market_snapshots_usdt) -->
				<?php
				$last_snapshots = [
					[ 'label' => 'Rapira',     'symbol' => 'USDT/RUB', 'row' => $last_rapira ],
					[ 'label' => 'Bitkub',     'symbol' => 'THB/USDT', 'row' => $last_bitkub ],
					[ 'label' => 'Binance TH', 'symbol' => 'USDT/THB', 'row' => $last_binance_th ],
				];
				$any_snapshot = $last_rapira || $last_bitkub || $last_binance_th;
				?>
				<?php if ( $any_snapshot ) : ?>
				<div class="row row-eq-height m-b-20">
					<?php foreach ( $last_snapshots as $snap ) :
						$row = $snap['row'];
					?>
					<div class="col-md-4 m-b-10 d-flex flex-column">
						<div class="card card-default no-margin h-100">
							<div class="card-body p-t-10 p-b-10 p-l-20 p-r-20 d-flex flex-column">
								<!-- Заголовок: источник слева, метка + дата справа -->
								<div class="d-flex justify-content-between align-items-start no-margin">
									<span class="font-montserrat fs-11 all-caps hint-text">
										<?php echo esc_html( $snap['label'] ); ?>
										<small class="normal m-l-3"><?php echo esc_html( $snap['symbol'] ); ?></small>
									</span>
									<div class="text-right">
										<p class="hint-text fs-10 no-margin">последнее сохранение</p>
										<?php if ( $row ) : ?>
										<p class="hint-text fs-10 no-margin"><?php echo esc_html( $row['created_at'] ); ?></p>
										<?php endif; ?>
									</div>
								</div>
								<!-- Значения: mt-auto прижимает к низу во всех трёх карточках одинаково -->
								<div class="d-flex align-items-baseline mt-auto p-t-10">
									<?php if ( $row ) : ?>
									<span class="hint-text fs-11 m-r-5">Bid</span>
									<strong class="fs-14 m-r-15"><?php echo $row['bid'] !== null ? number_format( (float) $row['bid'], 4 ) : '—'; ?></strong>
									<span class="hint-text fs-11 m-r-5">Ask</span>
									<strong class="fs-14"><?php echo $row['ask'] !== null ? number_format( (float) $row['ask'], 4 ) : '—'; ?></strong>
									<?php else : ?>
									<span class="hint-text fs-12">Нет сохранённых данных</span>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<?php if ( $ex24_ok && $pair ) : ?>
				<div class="row m-b-20">

					<!-- Конкурент: Sberbank -->
					<div class="col-md-3 col-sm-6 m-b-10">
						<div class="card card-default no-margin">
							<div class="card-header">
								<div class="card-title"><span class="font-montserrat fs-11 all-caps hint-text">Ex24 / Sberbank buy</span></div>
							</div>
							<div class="card-body p-t-10 p-b-15 p-l-20">
								<h3 class="no-margin semi-bold text-danger">
									<?php echo $comp_sber !== null ? number_format( $comp_sber, 4 ) : '—'; ?>
								</h3>
								<p class="hint-text small m-t-5 no-margin">Курс конкурента</p>
							</div>
						</div>
					</div>

					<!-- Наш: Sberbank -->
					<div class="col-md-3 col-sm-6 m-b-10">
						<div class="card card-default no-margin">
							<div class="card-header">
								<div class="card-title"><span class="font-montserrat fs-11 all-caps hint-text">Наш курс / Sberbank</span></div>
							</div>
							<div class="card-body p-t-10 p-b-15 p-l-20">
								<h3 class="no-margin semi-bold text-success">
									<?php echo $our_sber !== null ? number_format( $our_sber, 4 ) : '—'; ?>
								</h3>
								<p class="hint-text small m-t-5 no-margin">
									<?php echo $comp_sber !== null ? number_format( $comp_sber, 4 ) : '—'; ?>
									− <?php echo number_format( $coeff, 4 ); ?>
								</p>
							</div>
						</div>
					</div>

					<!-- Конкурент: Tinkoff -->
					<div class="col-md-3 col-sm-6 m-b-10">
						<div class="card card-default no-margin">
							<div class="card-header">
								<div class="card-title"><span class="font-montserrat fs-11 all-caps hint-text">Ex24 / Tinkoff buy</span></div>
							</div>
							<div class="card-body p-t-10 p-b-15 p-l-20">
								<h3 class="no-margin semi-bold text-danger">
									<?php echo $comp_tinkoff !== null ? number_format( $comp_tinkoff, 4 ) : '—'; ?>
								</h3>
								<p class="hint-text small m-t-5 no-margin">Курс конкурента</p>
							</div>
						</div>
					</div>

					<!-- Наш: Tinkoff -->
					<div class="col-md-3 col-sm-6 m-b-10">
						<div class="card card-default no-margin">
							<div class="card-header">
								<div class="card-title"><span class="font-montserrat fs-11 all-caps hint-text">Наш курс / Tinkoff</span></div>
							</div>
							<div class="card-body p-t-10 p-b-15 p-l-20">
								<h3 class="no-margin semi-bold text-success">
									<?php echo $our_tinkoff !== null ? number_format( $our_tinkoff, 4 ) : '—'; ?>
								</h3>
								<p class="hint-text small m-t-5 no-margin">
									<?php echo $comp_tinkoff !== null ? number_format( $comp_tinkoff, 4 ) : '—'; ?>
									− <?php echo number_format( $coeff, 4 ); ?>
								</p>
							</div>
						</div>
					</div>

				</div>

				<!-- Кнопка сохранения + мета-инфо -->
				<div class="d-flex align-items-center justify-content-between m-b-30">
					<p class="hint-text no-margin small">
						Пара: <strong><?php echo esc_html( $pair->title ); ?></strong>
						&nbsp;·&nbsp; Коэффициент: <strong><?php echo number_format( $coeff, 4 ); ?></strong>
						&nbsp;·&nbsp; Источник: <strong>Ex24 / <?php echo esc_html( RATES_PROVIDER_SOURCE ); ?></strong>
					</p>
					<button id="btn-save-rates" type="button" class="btn btn-primary btn-cons">
						<i class="pg-icon">save</i>&nbsp; Сохранить в историю
					</button>
				</div>
				<?php endif; ?>

				<!-- ─── Рыночные курсы USDT ────────────────────────────────────── -->
				<div class="m-b-5">
					<p class="font-montserrat fs-11 all-caps hint-text no-margin">Рыночные курсы USDT</p>
				</div>

				<!-- Алерты результата сохранения рыночных снимков -->
				<div id="market-save-alert" class="alert d-none m-b-15" role="alert"></div>

				<div class="row m-b-30">

					<!-- Rapira: USDT/RUB -->
					<div class="col-md-3 col-sm-6 m-b-10 d-flex flex-column">
						<div class="card card-default no-margin h-100">
							<div class="card-header">
								<div class="card-title">
									<span class="font-montserrat fs-11 all-caps hint-text">Rapira</span>
									<small class="hint-text m-l-5">USDT / RUB</small>
								</div>
							</div>
							<div class="card-body p-t-10 p-b-10 p-l-20 p-r-20">
								<div class="d-flex justify-content-between align-items-baseline">
									<div>
										<p class="hint-text fs-11 no-margin">Bid</p>
										<h4 class="no-margin semi-bold"><?php echo ( $rapira['ok'] && $rapira['bid'] !== null ) ? number_format( $rapira['bid'], 2 ) : '—'; ?></h4>
									</div>
									<div class="text-right">
										<p class="hint-text fs-11 no-margin">Ask</p>
										<h4 class="no-margin semi-bold"><?php echo ( $rapira['ok'] && $rapira['ask'] !== null ) ? number_format( $rapira['ask'], 2 ) : '—'; ?></h4>
									</div>
								</div>
							</div>
							<div class="card-footer p-t-8 p-b-8 p-l-20 p-r-20">
								<?php if ( $rapira['ok'] ) : ?>
								<button class="btn btn-xs btn-default btn-market-save" data-source="rapira" type="button">
									<i class="pg-icon fs-5">save</i> Сохранить
								</button>
								<?php else : ?>
								<span class="hint-text fs-10"><?php echo esc_html( $rapira['error'] ); ?></span>
								<?php endif; ?>
							</div>
						</div>
					</div>

					<!-- Bitkub: THB/USDT -->
					<div class="col-md-3 col-sm-6 m-b-10 d-flex flex-column">
						<div class="card card-default no-margin h-100">
							<div class="card-header">
								<div class="card-title">
									<span class="font-montserrat fs-11 all-caps hint-text">Bitkub</span>
									<small class="hint-text m-l-5">THB / USDT</small>
								</div>
							</div>
							<div class="card-body p-t-10 p-b-10 p-l-20 p-r-20">
								<div class="d-flex justify-content-between align-items-baseline">
									<div>
										<p class="hint-text fs-11 no-margin">Bid</p>
										<h4 class="no-margin semi-bold"><?php echo ( $bitkub['ok'] && $bitkub['highestBid'] !== null ) ? number_format( $bitkub['highestBid'], 4 ) : '—'; ?></h4>
									</div>
									<div class="text-right">
										<p class="hint-text fs-11 no-margin">Ask</p>
										<h4 class="no-margin semi-bold"><?php echo ( $bitkub['ok'] && $bitkub['lowestAsk'] !== null ) ? number_format( $bitkub['lowestAsk'], 4 ) : '—'; ?></h4>
									</div>
								</div>
							</div>
							<div class="card-footer p-t-8 p-b-8 p-l-20 p-r-20">
								<?php if ( $bitkub['ok'] ) : ?>
								<button class="btn btn-xs btn-default btn-market-save" data-source="bitkub" type="button">
									<i class="pg-icon fs-5">save</i> Сохранить
								</button>
								<?php else : ?>
								<span class="hint-text fs-10"><?php echo esc_html( $bitkub['error'] ); ?></span>
								<?php endif; ?>
							</div>
						</div>
					</div>

					<!-- Binance TH: USDT/THB -->
					<div class="col-md-3 col-sm-6 m-b-10 d-flex flex-column">
						<div class="card card-default no-margin h-100">
							<div class="card-header">
								<div class="card-title">
									<span class="font-montserrat fs-11 all-caps hint-text">Binance TH</span>
									<small class="hint-text m-l-5">USDT / THB</small>
								</div>
							</div>
							<div class="card-body p-t-10 p-b-10 p-l-20 p-r-20">
								<div class="d-flex justify-content-between align-items-baseline">
									<div>
										<p class="hint-text fs-11 no-margin">Bid</p>
										<h4 class="no-margin semi-bold"><?php echo ( $binance_th['ok'] && $binance_th['bid'] !== null ) ? number_format( $binance_th['bid'], 4 ) : '—'; ?></h4>
									</div>
									<div class="text-right">
										<p class="hint-text fs-11 no-margin">Ask</p>
										<h4 class="no-margin semi-bold"><?php echo ( $binance_th['ok'] && $binance_th['ask'] !== null ) ? number_format( $binance_th['ask'], 4 ) : '—'; ?></h4>
									</div>
								</div>
							</div>
							<div class="card-footer p-t-8 p-b-8 p-l-20 p-r-20">
								<?php if ( $binance_th['ok'] ) : ?>
								<button class="btn btn-xs btn-default btn-market-save" data-source="binance_th" type="button">
									<i class="pg-icon fs-5">save</i> Сохранить
								</button>
								<?php else : ?>
								<span class="hint-text fs-10"><?php echo esc_html( $binance_th['error'] ); ?></span>
								<?php endif; ?>
							</div>
						</div>
					</div>

					<!-- ЦБ РФ: USD/RUB — только информационный блок, без сохранения -->
					<div class="col-md-3 col-sm-6 m-b-10 d-flex flex-column">
						<div class="card card-default no-margin h-100" style="opacity:.82;">
							<div class="card-header">
								<div class="card-title d-flex align-items-center">
									<span class="font-montserrat fs-11 all-caps hint-text">ЦБ РФ</span>
									<small class="hint-text m-l-5">USD / RUB</small>
									<span class="badge badge-default m-l-auto fs-9" style="font-weight:400;letter-spacing:.3px;">справочно</span>
								</div>
							</div>
							<div class="card-body p-t-10 p-b-10 p-l-20 p-r-20">
								<?php if ( $cbr['ok'] ) : ?>
								<h4 class="no-margin semi-bold hint-text"><?php echo number_format( $cbr['rate'], 4 ); ?></h4>
								<?php if ( $cbr['date'] ) : ?>
								<p class="hint-text fs-10 no-margin m-t-3"><?php echo esc_html( $cbr['date'] ); ?></p>
								<?php endif; ?>
								<?php else : ?>
								<p class="hint-text small no-margin"><?php echo esc_html( $cbr['error'] ); ?></p>
								<?php endif; ?>
							</div>
							<div class="card-footer p-t-8 p-b-8 p-l-20 p-r-20">
								<span class="hint-text fs-10">Официальный курс, не сохраняется</span>
							</div>
						</div>
					</div>

				</div><!-- /.row market rates -->

				<!-- ─── Таблица истории рыночных снимков ────────────────────────── -->
				<div class="card card-transparent m-b-30">
					<div class="card-header">
						<div class="card-title">История сохранений — рыночные курсы USDT</div>
					</div>
					<div class="card-body">
						<table id="market-history-table" class="table table-hover">
							<thead>
								<tr>
									<th>Дата / время</th>
									<th>Источник</th>
									<th>Символ</th>
									<th>Bid</th>
									<th>Ask</th>
									<th>Mid</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $market_history as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['created_at'] ); ?></td>
									<td><?php echo esc_html( $row['source'] ); ?></td>
									<td class="hint-text"><?php echo esc_html( $row['symbol'] ); ?></td>
									<td><?php echo $row['bid'] !== null ? number_format( (float) $row['bid'], 4 ) : '—'; ?></td>
									<td><?php echo $row['ask'] !== null ? number_format( (float) $row['ask'], 4 ) : '—'; ?></td>
									<td class="hint-text"><?php echo $row['mid'] !== null ? number_format( (float) $row['mid'], 4 ) : '—'; ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>

				<!-- ─── График ──────────────────────────────────────────────────── -->
				<?php if ( ! empty( $chart_sber ) || ! empty( $chart_tinkoff ) ) : ?>
				<div class="card card-default m-b-30">
					<div class="card-header">
						<div class="card-title">История курсов (наши)</div>
					</div>
					<div class="card-body p-t-0">
						<div class="rates-chart-scroll">
							<div id="rates-chart" class="line-chart rates-line-chart" data-x-grid="false" data-stroke-width="2">
								<svg></svg>
							</div>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<!-- UI rule: стрелка в курсах означает "отдаём → получаем". -->
				<!-- ─── Таблица истории ──────────────────────────────────────────── -->
				<div class="card card-transparent m-b-30">
					<div class="card-header">
						<div class="card-title">История сохранений — ₽ → ฿</div>
						<p class="hint-text small m-t-5 no-margin">
							Колонка <span class="text-info semi-bold">«Наш Sberbank»</span> используется в Telegram-боте мерчантов
							как корпоративный курс по направлению <span class="semi-bold">₽ → ฿</span>.
							Бот берёт последнее сохранение из этой таблицы.
						</p>
					</div>
					<div class="card-body">
						<table id="rates-history-table" class="table table-hover">
							<thead>
								<tr>
									<th>Дата / время</th>
									<th>Ex24 Sberbank</th>
									<th class="text-info">
										Наш Sberbank
										<span class="label label-info m-l-5" style="font-weight:normal;">📡 ТГ-бот · ₽ → ฿</span>
									</th>
									<th>Ex24 Tinkoff</th>
									<th>Наш Tinkoff</th>
									<th>Коэф.</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $history as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['created_at'] ); ?></td>
									<td><?php echo $row['competitor_sberbank_buy'] !== null ? number_format( (float) $row['competitor_sberbank_buy'], 4 ) : '—'; ?></td>
									<td class="text-success semi-bold"><?php echo $row['our_sberbank_rate'] !== null ? number_format( (float) $row['our_sberbank_rate'], 4 ) : '—'; ?></td>
									<td><?php echo $row['competitor_tinkoff_buy'] !== null ? number_format( (float) $row['competitor_tinkoff_buy'], 4 ) : '—'; ?></td>
									<td class="text-success semi-bold"><?php echo $row['our_tinkoff_rate'] !== null ? number_format( (float) $row['our_tinkoff_rate'], 4 ) : '—'; ?></td>
									<td class="hint-text"><?php echo number_format( (float) $row['coefficient_value'], 4 ); ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>

			<?php if ( $kanyon_allowed ) : ?>
			<!-- ══ Kanyon USDT/RUB ════════════════════════════════════════════════════ -->
			<div class="rates-kanyon-section-title">
				<p class="font-montserrat fs-11 all-caps hint-text no-margin">Kanyon — проверка курса ₽ → ₮</p>
			</div>

			<div id="kanyon-alert" class="alert d-none m-b-15" role="alert"></div>

			<div class="row row-eq-height m-b-20 rates-kanyon-check">
				<div class="col-lg-3 col-md-4 col-sm-12 m-b-10 d-flex flex-column">
					<div class="card card-default no-margin h-100 rates-kanyon-card">
						<div class="card-header">
							<div class="card-title">
								<span class="font-montserrat fs-11 all-caps hint-text">Kanyon</span>
								<small class="hint-text m-l-5">₽ → ₮</small>
							</div>
						</div>
						<div class="card-body p-t-10 p-b-10 p-l-20 p-r-20">
							<p class="hint-text fs-11 no-margin">Последний курс</p>
							<h3 class="no-margin semi-bold text-success" id="kanyon-last-rate">
								<?php echo $last_kanyon ? number_format( (float) $last_kanyon['kanyon_rate'], 4 ) : '—'; ?>
							</h3>
							<p class="hint-text small m-t-5 no-margin" id="kanyon-last-at">
								<?php echo $last_kanyon ? 'Снято: ' . esc_html( $last_kanyon['created_at'] ) : 'Проверки ещё не проводились.'; ?>
							</p>
							<p class="hint-text fs-10 no-margin" id="kanyon-rapira-ref">
								<?php if ( $last_kanyon && $last_kanyon['rapira_rate'] !== null ) : ?>
								<?php
								$last_kanyon_rate  = (float) $last_kanyon['kanyon_rate'];
								$last_rapira_rate  = (float) $last_kanyon['rapira_rate'];
								$last_kanyon_ratio = $last_rapira_rate > 0 ? $last_kanyon_rate / $last_rapira_rate : null;
								?>
								Rapira: <?php echo number_format( $last_rapira_rate, 4 ); ?>
								<?php if ( $last_kanyon_ratio !== null ) : ?>
								· K/R: <?php echo number_format( $last_kanyon_ratio, 6 ); ?>
								<?php endif; ?>
								<?php endif; ?>
							</p>
						</div>
						<div class="card-footer p-t-8 p-b-8 p-l-20 p-r-20">
							<button type="button"
									id="btn-kanyon-check"
									class="btn btn-sm btn-primary rates-kanyon-button"
									<?php echo $kanyon_cooldown > 0 ? 'disabled' : ''; ?>>
								<i class="pg-icon m-r-5">refresh</i>Обновить
							</button>
							<span class="label label-warning rates-kanyon-cooldown" id="kanyon-cooldown-badge"<?php echo $kanyon_cooldown > 0 ? '' : ' style="display:none"'; ?>>
								<span id="kanyon-cooldown-secs"><?php echo (int) $kanyon_cooldown; ?></span> с.
							</span>
						</div>
					</div>
				</div>

				<div class="col-lg-9 col-md-8 col-sm-12 m-b-10 d-flex flex-column">
					<div class="card card-default no-margin h-100 rates-kanyon-info-card">
						<div class="card-header">
							<div class="card-title">Проверка курса</div>
						</div>
						<div class="card-body p-t-10 p-b-10 p-l-20 p-r-20">
							<p class="hint-text small no-margin rates-kanyon-note">
								Проверка создаёт тестовый ордер на 100 USDT, сохраняет курс Kanyon и свежий серверный курс Rapira.
								Лимит: не чаще одного раза в 30 минут на компанию. При выставлении финального счёта курс может немного измениться в обе стороны.
							</p>
						</div>
					</div>
				</div>
			</div>

			<div class="card card-transparent m-b-30 rates-kanyon-history-card">
				<div class="card-header">
					<div class="card-title">История сохранений — Kanyon ₽ → ₮</div>
					<p class="hint-text small m-t-5 no-margin">
						Колонка <span class="text-info semi-bold">«Kanyon»</span> используется для контроля курса провайдера
						по направлению <span class="semi-bold">₽ → ₮</span>.
					</p>
				</div>
				<div class="card-body">
					<table class="table table-hover" id="kanyon-history-table">
						<thead>
							<tr>
								<th>Дата / время</th>
								<th class="text-info">
									Kanyon
									<span class="label label-info m-l-5" style="font-weight:normal;">📡 ТГ-бот · ₽ → ₮</span>
								</th>
								<th>Rapira</th>
								<th>Коэф. K/R</th>
								<th>Разница</th>
								<th>Источник</th>
							</tr>
						</thead>
						<tbody id="kanyon-history-tbody">
							<?php foreach ( $kanyon_history as $row ) : ?>
							<?php
							$kanyon_value = (float) $row['kanyon_rate'];
							$rapira_value = $row['rapira_rate'] !== null ? (float) $row['rapira_rate'] : null;
							$diff_value   = $rapira_value !== null ? $kanyon_value - $rapira_value : null;
							$ratio_value  = ( $rapira_value !== null && $rapira_value > 0 ) ? $kanyon_value / $rapira_value : null;
							?>
							<tr>
								<td><?php echo esc_html( $row['created_at'] ); ?></td>
								<td class="text-success semi-bold"><?php echo number_format( $kanyon_value, 4 ); ?></td>
								<td><?php echo $rapira_value !== null ? number_format( $rapira_value, 4 ) : '—'; ?></td>
								<td class="semi-bold"><?php echo $ratio_value !== null ? number_format( $ratio_value, 6 ) : '—'; ?></td>
								<td class="hint-text"><?php echo $diff_value !== null ? number_format( $diff_value, 4 ) : '—'; ?></td>
								<td>
									<?php
									switch ( $row['source'] ) {
										case 'telegram':
											echo '<span class="label label-info">Telegram</span>';
											break;
										case 'cron':
											echo '<span class="label label-warning">cron</span>';
											break;
										default:
											echo '<span class="label label-default">web</span>';
									}
									?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php endif; // kanyon_allowed ?>

			</div><!-- /.container-fluid -->
		</div><!-- /.content -->

		<?php get_template_part( 'template-parts/footer-backoffice' ); ?>
	</div><!-- /.page-content-wrapper -->
</div><!-- /.page-container -->

<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>

<?php
add_action( 'wp_footer', function () use ( $nonce_save, $nonce_market_save, $chart_sber, $chart_tinkoff, $ex24_ok, $nonce_kanyon, $kanyon_allowed, $kanyon_cooldown ) {
?>
<script>
(function ($) {
	'use strict';

	var AJAX_URL          = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCE             = '<?php echo esc_js( $nonce_save ); ?>';
	var NONCE_MARKET      = '<?php echo esc_js( $nonce_market_save ); ?>';
	var EX24_OK           = <?php echo $ex24_ok ? 'true' : 'false'; ?>;
	var RATES_DT_LANGUAGE = {
		'sInfo'       : 'Записи _START_–_END_ из _TOTAL_',
		'sInfoEmpty'  : 'Записи 0–0 из 0',
		'sEmptyTable' : 'Нет сохранённых записей',
		'sZeroRecords': 'Нет подходящих записей',
		'oPaginate'   : {
			'sFirst'   : '«',
			'sPrevious': '‹',
			'sNext'    : '›',
			'sLast'    : '»'
		}
	};
	var RATES_DT_DOM = "<'rates-dt-table't><'rates-dt-footer'<'rates-dt-info'i><'rates-dt-pager'p>>";

	// ── DataTables ────────────────────────────────────────────────────────────
	var marketDT = $('#market-history-table').dataTable({
		'sDom'          : RATES_DT_DOM,
		'destroy'       : true,
		'order'         : [[ 0, 'desc' ]],
		'iDisplayLength': 15,
		'bFilter'       : false,
		'oLanguage'     : RATES_DT_LANGUAGE
	});

	$('#rates-history-table').dataTable({
		'sDom'          : RATES_DT_DOM,
		'destroy'       : true,
		'order'         : [[ 0, 'desc' ]],
		'iDisplayLength': 15,
		'bFilter'       : false,
		'oLanguage'     : RATES_DT_LANGUAGE
	});

	var kanyonDT = null;
	if ($('#kanyon-history-table').length) {
		kanyonDT = $('#kanyon-history-table').dataTable({
			'sDom'          : RATES_DT_DOM,
			'destroy'       : true,
			'order'         : [[ 0, 'desc' ]],
			'iDisplayLength': 15,
			'bFilter'       : false,
			'oLanguage'     : RATES_DT_LANGUAGE
		});
	}

	// ── NVD3 Line Chart ───────────────────────────────────────────────────────
	var chartSber    = <?php echo crm_json_for_inline_js( $chart_sber ); ?>;
	var chartTinkoff = <?php echo crm_json_for_inline_js( $chart_tinkoff ); ?>;

	if ( (chartSber.length > 0 || chartTinkoff.length > 0) && typeof nv !== 'undefined' ) {
		var chartData = [];
		if (chartSber.length > 0) {
			chartData.push({
				key   : 'Sberbank (наш)',
				values: chartSber.map(function(d){ return { x: d[0], y: d[1] }; })
			});
		}
		if (chartTinkoff.length > 0) {
			chartData.push({
				key   : 'Tinkoff (наш)',
				values: chartTinkoff.map(function(d){ return { x: d[0], y: d[1] }; })
			});
		}

		function ratesChartWidth() {
			var $chart = $('#rates-chart');
			var width  = Math.floor($chart.outerWidth() || $chart.parent().width() || 0);
			return width > 0 ? Math.max(width, 420) : 0;
		}

		function ratesChartMargin(width) {
			if (width < 560) {
				return { left: 50, right: 12, top: 18, bottom: 36 };
			}
			return { left: 70, right: 30, top: 20, bottom: 40 };
		}

		function ratesRenderChart(chart) {
			var width = ratesChartWidth();
			if (!width) return false;

			chart
				.width(width)
				.height(260)
				.margin(ratesChartMargin(width));

			d3.select('#rates-chart svg')
				.attr('width', width)
				.attr('height', 260)
				.datum(chartData)
				.call(chart);

			return true;
		}

		nv.addGraph(function () {
			var chart = nv.models.lineChart()
				.x(function (d) { return d.x; })
				.y(function (d) { return d.y; })
				.useInteractiveGuideline(true)
				.showLegend(true);

			chart.xAxis
				.tickFormat(function (d) {
					return d3.time.format('%d.%m %H:%M')(new Date(d));
				});

			chart.yAxis
				.tickFormat(d3.format(',.4f'));

			ratesRenderChart(chart);

			var resizeTimer = null;
			nv.utils.windowResize(function () {
				clearTimeout(resizeTimer);
				resizeTimer = setTimeout(function () {
					ratesRenderChart(chart);
				}, 120);
			});
			return chart;
		});
	}

	// ── Кнопки сохранения рыночных снимков (Rapira / Bitkub / Binance TH) ───
	$('.btn-market-save').on('click', function () {
		var $btn    = $(this);
		var source  = $btn.data('source');
		var $alert  = $('#market-save-alert');

		if ($btn.hasClass('disabled') || $btn.prop('disabled')) return;

		$btn.prop('disabled', true);
		$alert.addClass('d-none').removeClass('alert-success alert-danger');

		$.post(AJAX_URL, {
			action: 'me_market_snapshot_save',
			nonce : NONCE_MARKET,
			source: source
		})
		.done(function (res) {
			if (res.success) {
				var d   = res.data;
				var bid = d.bid !== null ? parseFloat(d.bid).toFixed(4) : '—';
				var ask = d.ask !== null ? parseFloat(d.ask).toFixed(4) : '—';
				var mid = d.mid !== null ? parseFloat(d.mid).toFixed(4) : '—';

				$alert
					.removeClass('d-none alert-danger')
					.addClass('alert-success')
					.html('<strong>Сохранено.</strong> ' + d.symbol + ' &nbsp;bid: ' + bid + ' &nbsp;ask: ' + ask);

				// Добавляем строку в таблицу — DataTables сам применит текущую сортировку [0, desc]
				marketDT.fnAddData([
					d.created_at,
					d.source,
					d.symbol,
					bid,
					ask,
					mid
				]);
			} else {
				$alert
					.removeClass('d-none alert-success')
					.addClass('alert-danger')
					.text(res.data.message || 'Ошибка сохранения.');
			}
			$btn.prop('disabled', false);
		})
		.fail(function (xhr) {
			var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
				? xhr.responseJSON.data.message
				: ('Ошибка сохранения (HTTP ' + (xhr ? xhr.status : '?') + ').');
			console.error('[me_market_snapshot_save] failed:', xhr && xhr.status, xhr && xhr.responseText);
			$alert
				.removeClass('d-none alert-success')
				.addClass('alert-danger')
				.text(msg);
			$btn.prop('disabled', false);
		});
	});

	// ── Кнопка сохранения ────────────────────────────────────────────────────
	if (EX24_OK) {
		$('#btn-save-rates').on('click', function () {
			var $btn   = $(this);
			var $alert = $('#rates-save-alert');
			var BTN_LABEL = '<i class="pg-icon">save</i>&nbsp; Сохранить в историю';

			function resetBtn() {
				$btn.prop('disabled', false).html(BTN_LABEL);
			}

			$btn.prop('disabled', true).html('<i class="pg-icon">save</i>&nbsp; Сохраняем…');
			$alert.addClass('d-none').removeClass('alert-success alert-danger');

			$.post(AJAX_URL, {
				action: 'me_rates_save',
				nonce : NONCE
			})
			.done(function (res) {
				if (res.success) {
					var savePrefix = res.data && res.data.saved ? 'Сохранено.' : 'Без изменений.';
					$alert
						.removeClass('d-none alert-danger')
						.addClass('alert-success')
						.html(
							'<strong>' + savePrefix + '</strong> ' +
							'Sberbank: ' + res.data.our_sberbank + ' &nbsp;|&nbsp; ' +
							'Tinkoff: '  + res.data.our_tinkoff  + ' &nbsp;|&nbsp; ' +
							'Коэф.: '   + res.data.coefficient
						);
					setTimeout(function () { location.reload(); }, 1500);
				} else {
					$alert
						.removeClass('d-none alert-success')
						.addClass('alert-danger')
						.text(res.data.message || 'Ошибка сохранения.');
					resetBtn();
				}
			})
			.fail(function (xhr) {
				var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
					? xhr.responseJSON.data.message
					: ('Ошибка сохранения (HTTP ' + (xhr ? xhr.status : '?') + ').');
				console.error('[me_rates_save] failed:', xhr && xhr.status, xhr && xhr.responseText);
				$alert
					.removeClass('d-none alert-success')
					.addClass('alert-danger')
					.text(msg);
				resetBtn();
			});
		});
	}

	// ── Kanyon rate check ─────────────────────────────────────────────────────
	<?php if ( $kanyon_allowed ) : ?>
	(function () {
		var NONCE_KANYON       = '<?php echo esc_js( $nonce_kanyon ); ?>';
		var $btn               = $('#btn-kanyon-check');
		var $alert             = $('#kanyon-alert');
		var $badge             = $('#kanyon-cooldown-badge');
		var $secs              = $('#kanyon-cooldown-secs');
		var cooldownRemaining  = <?php echo (int) $kanyon_cooldown; ?>;
		var cooldownTimer      = null;
		var BTN_LABEL          = '<i class="pg-icon m-r-5">refresh</i>Обновить';

		function kanyonSourceBadge(source) {
			if (source === 'telegram') return '<span class="label label-info">Telegram</span>';
			if (source === 'cron') return '<span class="label label-warning">cron</span>';
			return '<span class="label label-default">web</span>';
		}

		function kanyonRapiraRatio(kanyonRate, rapiraRate) {
			var k = parseFloat(kanyonRate);
			var r = parseFloat(rapiraRate);
			if (!isFinite(k) || !isFinite(r) || r <= 0) return '—';
			return (k / r).toFixed(6);
		}

		function startCooldownUI(remaining) {
			cooldownRemaining = remaining;
			$btn.prop('disabled', true).html(BTN_LABEL);
			$badge.show();
			clearInterval(cooldownTimer);
			cooldownTimer = setInterval(function () {
				cooldownRemaining--;
				if (cooldownRemaining <= 0) {
					clearInterval(cooldownTimer);
					$btn.prop('disabled', false).html(BTN_LABEL);
					$badge.hide();
					$secs.text('0');
				} else {
					$secs.text(cooldownRemaining);
				}
			}, 1000);
			$secs.text(cooldownRemaining);
		}

		if (cooldownRemaining > 0) {
			startCooldownUI(cooldownRemaining);
		}

		$btn.on('click', function () {
			$alert.addClass('d-none').removeClass('alert-success alert-danger');
			$btn.prop('disabled', true).html('<i class="pg-icon m-r-5">refresh</i>Запрашиваем…');

			$.post(AJAX_URL, {
				action: 'me_kanyon_rate_check',
				_nonce: NONCE_KANYON
			})
			.done(function (res) {
				if (res.success) {
					var d = res.data;
					var kanyon = parseFloat(d.kanyon_rate).toFixed(4);
					var rapira = d.rapira_rate !== null && d.rapira_rate !== undefined ? parseFloat(d.rapira_rate).toFixed(4) : '—';
					var diff   = rapira !== '—' ? (parseFloat(d.kanyon_rate) - parseFloat(d.rapira_rate)).toFixed(4) : '—';
					var ratio  = kanyonRapiraRatio(d.kanyon_rate, d.rapira_rate);
					var takenAt = d.created_at || 'только что';

					$('#kanyon-last-rate').text(kanyon);
					$('#kanyon-last-at').text('Снято: ' + takenAt);
					$('#kanyon-rapira-ref').text(rapira !== '—' ? 'Rapira: ' + rapira + ' · K/R: ' + ratio : '');

					$alert
						.removeClass('d-none alert-danger')
						.addClass('alert-success')
						.html('<strong>Готово.</strong> Kanyon ₽ → ₮: ' + kanyon + ' ₽');

					if (kanyonDT && d.created_at) {
						kanyonDT.fnAddData([
							d.created_at,
							'<span class="text-success semi-bold">' + kanyon + '</span>',
							rapira,
							'<span class="semi-bold">' + ratio + '</span>',
							diff,
							kanyonSourceBadge(d.source)
						]);
					}

					startCooldownUI(d.cooldown_remaining || <?php echo RATES_KANYON_COOLDOWN_TTL; ?>);
				} else {
					$btn.html(BTN_LABEL);
					var cooldown = res.data && res.data.cooldown;
					if (cooldown) {
						startCooldownUI(cooldown);
					} else {
						$btn.prop('disabled', false);
					}
					$alert
						.removeClass('d-none alert-success')
						.addClass('alert-danger')
						.text(res.data && res.data.message ? res.data.message : 'Ошибка запроса.');
				}
			})
			.fail(function (xhr) {
				$btn.html(BTN_LABEL);
				var data     = xhr && xhr.responseJSON && xhr.responseJSON.data;
				var msg      = data && data.message ? data.message : 'Ошибка HTTP ' + (xhr ? xhr.status : '?');
				var cooldown = data && data.cooldown;
				if (cooldown) {
					startCooldownUI(cooldown);
				} else {
					$btn.prop('disabled', false);
				}
				$alert
					.removeClass('d-none alert-success')
					.addClass('alert-danger')
					.text(msg);
			});
		});
	})();
	<?php endif; ?>

}(jQuery));
</script>
<?php
}, 99 );
?>

<?php get_footer(); ?>

<?php
/**
 * Блок настройки наценки валютной пары.
 *
 * Args:
 *   - pair_code        string  Код пары (THB_RUB, USDT_THB, RUB_USDT)
 *   - pair_title       string  Заголовок пары (RUB/THB и т.п.)
 *   - state            string  'active' | 'disabled' | 'not_configured'
 *   - coefficient      float   Текущее значение наценки (если запись есть)
 *   - coefficient_type string  'absolute' | 'percent'
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = wp_parse_args(
	$args ?? [],
	[
		'pair_code'         => '',
		'pair_title'        => '',
		'state'             => 'not_configured',
		'coefficient'       => null,
		'coefficient_type'  => 'absolute',
		'has_market_source' => false,
		'market_source'     => 'bitkub',
	]
);

$pair_code         = (string) $args['pair_code'];
$pair_title        = (string) $args['pair_title'];
$state             = (string) $args['state'];
$coefficient       = $args['coefficient'];
$coefficient_type  = in_array( (string) $args['coefficient_type'], [ 'absolute', 'percent' ], true )
	? (string) $args['coefficient_type']
	: 'absolute';
$has_market_source = ! empty( $args['has_market_source'] );
$market_source     = in_array( (string) $args['market_source'], [ 'bitkub', 'binance_th' ], true )
	? (string) $args['market_source']
	: 'bitkub';

if ( $pair_code === '' || $pair_title === '' ) {
	return;
}

$state_badge = [
	'active'         => '<span class="badge badge-success">активна</span>',
	'disabled'       => '<span class="badge badge-secondary">отключена root\'ом</span>',
	'not_configured' => '<span class="badge badge-secondary">не настроена</span>',
][ $state ] ?? '';

$slug      = sanitize_html_class( strtolower( $pair_code ) );
$id_value  = 'rates_value_' . $slug;
$name_type = 'rates_type_' . $slug;
?>
<div class="card card-default m-b-30 rates-coefficient-card" data-pair-code="<?php echo esc_attr( $pair_code ); ?>">
	<div class="card-header">
		<div class="card-title">
			Курсы — <?php echo esc_html( $pair_title ); ?>
			<span class="m-l-10"><?php echo $state_badge; ?></span>
		</div>
	</div>
	<div class="card-body">
		<?php if ( $state === 'active' ) : ?>
			<form class="rates-coefficient-form" data-pair-code="<?php echo esc_attr( $pair_code ); ?>">
				<div class="row">
					<div class="col-md-5 col-lg-4">
						<div class="form-group">
							<label class="d-block m-b-5">Тип наценки</label>
							<div class="form-check form-check-inline m-r-15">
								<input type="radio"
								       class="form-check-input rates-type-input"
								       id="<?php echo esc_attr( $name_type . '_absolute' ); ?>"
								       name="<?php echo esc_attr( $name_type ); ?>"
								       value="absolute"
								       <?php checked( $coefficient_type, 'absolute' ); ?>>
								<label class="form-check-label" for="<?php echo esc_attr( $name_type . '_absolute' ); ?>">Сдвиг</label>
							</div>
							<div class="form-check form-check-inline">
								<input type="radio"
								       class="form-check-input rates-type-input"
								       id="<?php echo esc_attr( $name_type . '_percent' ); ?>"
								       name="<?php echo esc_attr( $name_type ); ?>"
								       value="percent"
								       <?php checked( $coefficient_type, 'percent' ); ?>>
								<label class="form-check-label" for="<?php echo esc_attr( $name_type . '_percent' ); ?>">Процент</label>
							</div>
						</div>
					</div>
					<div class="col-md-4 col-lg-3">
						<div class="form-group">
							<label for="<?php echo esc_attr( $id_value ); ?>">
								Значение
								<span class="rates-value-unit hint-text" data-unit-absolute="(абсолютное число)" data-unit-percent="(%)">
									<?php echo $coefficient_type === 'percent' ? '(%)' : '(абсолютное число)'; ?>
								</span>
							</label>
							<input type="number"
							       class="form-control rates-coefficient-input"
							       id="<?php echo esc_attr( $id_value ); ?>"
							       value="<?php echo esc_attr( number_format( (float) ( $coefficient ?? 0 ), 4, '.', '' ) ); ?>"
							       step="0.0001"
							       min="0"
							       placeholder="0.0000">
						</div>
					</div>
				</div>
				<p class="hint-text small m-b-15 rates-formula-hint"
				   data-hint-absolute="Сдвиг: наш курс = курс конкурента − значение."
				   data-hint-percent="Процент: наш курс = курс конкурента × (1 + значение / 100).">
					<?php echo $coefficient_type === 'percent'
						? 'Процент: наш курс = курс конкурента × (1 + значение / 100).'
						: 'Сдвиг: наш курс = курс конкурента − значение.'; ?>
				</p>
				<button type="submit" class="btn btn-primary btn-cons">
					Сохранить
				</button>
			</form>

			<?php if ( $has_market_source ) : ?>
			<hr class="m-t-20 m-b-25">
			<form class="rates-source-form" data-pair-code="<?php echo esc_attr( $pair_code ); ?>">
				<h6 class="semi-bold m-b-5">Источник котировки</h6>
				<p class="hint-text small m-b-15">
					Биржа, с которой берётся <strong>опорный рыночный курс</strong> для этого направления.
					Именно на него накладывается наценка выше. Курс используется при расчёте стоимости обменов
					и отображается мерчантам и клиентам в боте.
				</p>
				<div class="form-group m-b-15">
					<label class="d-block m-b-8">Биржа</label>
					<div class="form-check form-check-inline m-r-20">
						<input type="radio"
						       class="form-check-input"
						       id="src_bitkub_<?php echo esc_attr( $slug ); ?>"
						       name="market_source_<?php echo esc_attr( $slug ); ?>"
						       value="bitkub"
						       <?php checked( $market_source, 'bitkub' ); ?>>
						<label class="form-check-label" for="src_bitkub_<?php echo esc_attr( $slug ); ?>">
							Bitkub
						</label>
					</div>
					<div class="form-check form-check-inline">
						<input type="radio"
						       class="form-check-input"
						       id="src_binance_<?php echo esc_attr( $slug ); ?>"
						       name="market_source_<?php echo esc_attr( $slug ); ?>"
						       value="binance_th"
						       <?php checked( $market_source, 'binance_th' ); ?>>
						<label class="form-check-label" for="src_binance_<?php echo esc_attr( $slug ); ?>">
							Binance TH
						</label>
					</div>
				</div>
				<button type="submit" class="btn btn-primary btn-cons">Сохранить</button>
			</form>
			<?php endif; ?>

		<?php elseif ( $state === 'disabled' ) : ?>
			<p class="hint-text no-margin">
				Пара <?php echo esc_html( $pair_title ); ?> отключена администратором системы (root) для вашей компании.
				Изменение наценки недоступно. Чтобы возобновить работу по этому направлению — обратитесь к root.
				<?php if ( function_exists( 'crm_is_root' ) && crm_is_root( get_current_user_id() ) ) : ?>
					<a href="<?php echo esc_url( home_url( '/root-rate-pairs/' ) ); ?>" class="m-l-5">Открыть «Курсы и пары»</a>
				<?php endif; ?>
			</p>
			<?php if ( $coefficient !== null ) : ?>
				<p class="hint-text small m-t-10 no-margin">
					Последнее значение:
					<strong>
						<?php
						echo esc_html(
							$coefficient_type === 'percent'
								? rtrim( rtrim( number_format( (float) $coefficient, 4, '.', '' ), '0' ), '.' ) . '%'
								: number_format( (float) $coefficient, 4 )
						);
						?>
					</strong>
					(<?php echo esc_html( $coefficient_type === 'percent' ? 'процент' : 'сдвиг' ); ?>)
				</p>
			<?php endif; ?>
		<?php else : ?>
			<p class="hint-text no-margin">
				Пара <?php echo esc_html( $pair_title ); ?> не активирована для вашей компании.
				Активацию производит администратор системы (root) на странице «Курсы и пары».
				<?php if ( function_exists( 'crm_is_root' ) && crm_is_root( get_current_user_id() ) ) : ?>
					<a href="<?php echo esc_url( home_url( '/root-rate-pairs/' ) ); ?>" class="m-l-5">Открыть «Курсы и пары»</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</div>
</div>

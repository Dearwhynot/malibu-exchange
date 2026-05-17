<?php
/**
 * Общий блок формы создания платёжного ордера.
 *
 * Подключается через get_template_part('template-parts/order-create-form').
 * Содержит только поля (сумма по актуальному contour компании + комментарий + блок alert'а) без <form>-обёртки
 * и без кнопки submit — внешнюю оболочку (card / modal-body / и т.п.) и submit-кнопку
 * даёт каждая страница сама.
 *
 * Выставляет в window:
 *   MalibuOrderCreate.submitFromForm({ ajaxUrl, nonce, onSuccess(data), onError(msg)?, onEnd()? })
 *     → читает значения полей #moc-amount-value / #moc-description, валидирует,
 *       показывает alert в #moc-alert, отправляет AJAX `me_orders_create`.
 *   MalibuOrderCreate.reset()        → очищает поля и скрывает alert.
 *   MalibuOrderCreate.showAlert(msg, type)
 *   MalibuOrderCreate.hideAlert()
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Подключаем один раз на страницу.
if ( defined( 'MALIBU_ORDER_CREATE_FORM_LOADED' ) ) {
	return;
}
define( 'MALIBU_ORDER_CREATE_FORM_LOADED', true );

$current_user_id = get_current_user_id();
$company_id      = function_exists( 'crm_get_current_user_company_id' )
	? (int) crm_get_current_user_company_id( $current_user_id )
	: 0;
$input_mode      = function_exists( 'crm_fintech_company_create_order_input_mode' )
	? crm_fintech_company_create_order_input_mode( $company_id )
	: 'usdt';
$is_rub_mode     = $input_mode === 'rub';
$amount_label    = $is_rub_mode ? 'Сумма в RUB' : 'Сумма в USDT';
$amount_suffix   = $is_rub_mode ? 'RUB' : 'USDT';
$amount_example  = $is_rub_mode ? '30000' : '100.00';
$amount_hint     = $is_rub_mode
	? 'У компании включён рублёвый contour Kanyon. Введите сумму, которую клиент должен оплатить в RUB; итоговая сумма в USDT будет рассчитана провайдером.'
	: 'Введите сумму в USDT. Конвертация в RUB производится провайдером.';
$amount_error    = $is_rub_mode
	? 'Введите корректную сумму RUB.'
	: 'Введите корректную сумму USDT.';
$default_payment_purpose = '';

if ( $company_id > 0 && function_exists( 'crm_fintech_get_pay2day_default_payment_purpose' ) ) {
	$default_payment_purpose = crm_fintech_get_pay2day_default_payment_purpose( $company_id );
}
?>

<div id="moc-alert" class="alert d-none m-b-15" role="alert"></div>

<div class="form-group">
	<input type="hidden" id="moc-amount-mode" value="<?php echo esc_attr( $input_mode ); ?>">
	<label for="moc-amount-value"><?php echo esc_html( $amount_label ); ?> <span class="text-danger">*</span></label>
	<div class="input-group">
		<input type="number" id="moc-amount-value" name="amount_value"
		       class="form-control" min="0.01" step="0.01"
		       placeholder="<?php echo esc_attr( 'например ' . $amount_example ); ?>" required autocomplete="off">
		<span class="input-group-text"><?php echo esc_html( $amount_suffix ); ?></span>
	</div>
	<p class="hint-text m-t-5"><?php echo esc_html( $amount_hint ); ?></p>
</div>

<div class="form-group">
	<label for="moc-description">Назначение платежа <span class="text-muted">(можно изменить)</span></label>
	<input type="text" id="moc-description" name="description"
	       class="form-control" maxlength="200"
	       value="<?php echo esc_attr( $default_payment_purpose ); ?>"
	       placeholder="например, Одежда">
	<p class="hint-text m-t-5">
		<?php if ( $default_payment_purpose !== '' ) : ?>
			Подставлено значение по умолчанию из настроек компании. При необходимости его можно заменить перед выпуском ордера.
		<?php else : ?>
			Если поле оставить пустым, будет использовано значение по умолчанию из настроек компании, если оно задано.
		<?php endif; ?>
	</p>
</div>

<?php
add_action( 'wp_footer', function () {
?>
<script>
(function ($) {
	'use strict';

	function showAlert(msg, type) {
		$('#moc-alert')
			.removeClass('d-none alert-success alert-danger alert-warning alert-info')
			.addClass('alert-' + (type || 'danger'))
			.text(msg);
	}
	function hideAlert() { $('#moc-alert').addClass('d-none'); }

	function reset() {
		$('#moc-amount-value').val('');
		$('#moc-description').val(<?php echo wp_json_encode( $default_payment_purpose, JSON_UNESCAPED_UNICODE ); ?>);
		hideAlert();
	}

	function submitFromForm(opts) {
		opts = opts || {};
		var ajaxUrl   = opts.ajaxUrl;
		var nonce     = opts.nonce;
		var onSuccess = opts.onSuccess || function () {};
		var onError   = opts.onError   || function (msg) { showAlert(msg, 'danger'); };
		var onEnd     = opts.onEnd     || function () {};

		var mode   = String($('#moc-amount-mode').val() || 'usdt').toLowerCase();
		var amount = parseFloat($('#moc-amount-value').val());
		var desc   = $('#moc-description').val();
		var errorText = <?php echo wp_json_encode( $amount_error, JSON_UNESCAPED_UNICODE ); ?>;

		if (isNaN(amount) || amount <= 0) {
			showAlert(errorText, 'danger');
			onEnd();
			return;
		}

		hideAlert();

		$.post(ajaxUrl, {
			action:      'me_orders_create',
			_nonce:      nonce,
			amount_value: amount,
			amount_mode: mode,
			description: desc,
		})
		.done(function (res) {
			if (!res.success) {
				onError(res.data ? res.data.message : 'Ошибка создания ордера.');
				return;
			}
			onSuccess(res.data, { amount: amount, amountMode: mode, description: desc });
		})
		.fail(function (jqXHR) {
			var msg = 'Сетевая ошибка. Попробуйте ещё раз.';
			if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
				msg = jqXHR.responseJSON.data.message;
			}
			onError(msg);
		})
		.always(function () { onEnd(); });
	}

	window.MalibuOrderCreate = {
		submitFromForm: submitFromForm,
		reset:          reset,
		showAlert:      showAlert,
		hideAlert:      hideAlert,
	};

}(jQuery));
</script>
<?php
}, 98 );
?>

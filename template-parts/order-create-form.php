<?php
/**
 * Общий блок формы создания платёжного ордера.
 *
 * Подключается через get_template_part('template-parts/order-create-form').
 * Содержит только поля (сумма USDT + комментарий + блок alert'а) без <form>-обёртки
 * и без кнопки submit — внешнюю оболочку (card / modal-body / и т.п.) и submit-кнопку
 * даёт каждая страница сама.
 *
 * Выставляет в window:
 *   MalibuOrderCreate.submitFromForm({ ajaxUrl, nonce, onSuccess(data), onError(msg)?, onEnd()? })
 *     → читает значения полей #moc-amount-usdt / #moc-description, валидирует,
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
?>

<div id="moc-alert" class="alert d-none m-b-15" role="alert"></div>

<div class="form-group">
	<label for="moc-amount-usdt">Сумма в USDT <span class="text-danger">*</span></label>
	<div class="input-group">
		<input type="number" id="moc-amount-usdt" name="amount_usdt"
		       class="form-control" min="0.01" step="0.01"
		       placeholder="например 100.00" required autocomplete="off">
		<span class="input-group-text">USDT</span>
	</div>
	<p class="hint-text m-t-5">Введите сумму в USDT. Конвертация в RUB производится провайдером.</p>
</div>

<div class="form-group">
	<label for="moc-description">Комментарий <span class="text-muted">(необязательно)</span></label>
	<input type="text" id="moc-description" name="description"
	       class="form-control" maxlength="200"
	       placeholder="Назначение платежа или заметка">
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
		$('#moc-amount-usdt').val('');
		$('#moc-description').val('');
		hideAlert();
	}

	function submitFromForm(opts) {
		opts = opts || {};
		var ajaxUrl   = opts.ajaxUrl;
		var nonce     = opts.nonce;
		var onSuccess = opts.onSuccess || function () {};
		var onError   = opts.onError   || function (msg) { showAlert(msg, 'danger'); };
		var onEnd     = opts.onEnd     || function () {};

		var amount = parseFloat($('#moc-amount-usdt').val());
		var desc   = $('#moc-description').val();

		if (isNaN(amount) || amount <= 0) {
			showAlert('Введите корректную сумму USDT.', 'danger');
			onEnd();
			return;
		}

		hideAlert();

		$.post(ajaxUrl, {
			action:      'me_orders_create',
			_nonce:      nonce,
			amount_usdt: amount,
			description: desc,
		})
		.done(function (res) {
			if (!res.success) {
				onError(res.data ? res.data.message : 'Ошибка создания ордера.');
				return;
			}
			onSuccess(res.data, { amount: amount, description: desc });
		})
		.fail(function () {
			onError('Сетевая ошибка. Попробуйте ещё раз.');
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

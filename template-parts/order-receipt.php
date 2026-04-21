<?php
/**
 * Общий блок «QR-чека» заказа.
 *
 * Подключается через get_template_part('template-parts/order-receipt').
 * Ожидает также подключения get_template_part('template-parts/toast-host')
 * для уведомлений (MalibuToast). Если toast-host отсутствует — тихо работает без тостов.
 *
 * API:
 *   MalibuOrderReceipt.configure({ ajaxUrl, nonce })
 *     — задать контекст AJAX-проверки статуса (me_orders_check_status, nonce 'me_orders_list').
 *
 *   MalibuOrderReceipt.render(data)              → HTML-строка с чеком
 *   MalibuOrderReceipt.showModal(data, opts?)    → показать в общей модалке
 *   MalibuOrderReceipt.renderInto($el, data, opts?)  → встроить в контейнер
 *   MalibuOrderReceipt.showModalLoading() / showModalError(msg)
 *
 * data:
 *   id                    — обязательно для кнопки «Проверить»
 *   merchant_order_id
 *   status_code           — 'created'|'pending'|'paid'|'declined'|'cancelled'|'expired'|'error'
 *   payment_amount_value  (RUB)
 *   qr_url
 *   created_at            ('YYYY-MM-DD HH:MM:SS')
 *   paid_at               (optional, для paid-состояния)
 *
 * opts:
 *   onStatusChange(newData) — коллбэк после успешной смены статуса (вызывается 1 раз).
 *                             newData = { id, old_status, new_status, message, original }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'MALIBU_ORDER_RECEIPT_LOADED' ) ) {
	return;
}
define( 'MALIBU_ORDER_RECEIPT_LOADED', true );
?>

<!-- ─── Общая модалка QR-чека ────────────────────────────────────────────── -->
<div class="modal fade" id="order-receipt-modal" tabindex="-1" role="dialog"
     aria-labelledby="order-receipt-title" aria-hidden="true">
	<div class="modal-dialog" style="max-width:380px;margin:1.75rem auto">
		<div class="modal-content" style="border-radius:14px;overflow:hidden;border:none;box-shadow:0 8px 40px rgba(0,0,0,.22)">
			<div id="order-receipt-body">
				<div class="text-center p-t-30 p-b-30 text-muted">Загрузка…</div>
			</div>
			<div class="border-0 p-b-20 p-t-5 d-flex justify-content-center">
				<button type="button" class="btn btn-default btn-sm" data-bs-dismiss="modal">Закрыть</button>
			</div>
		</div>
	</div>
</div>

<style>
/* ── QR-чек ───────────────────────────────────────────────────────────────── */
.receipt-wrap { background:#fff; font-family:'Helvetica Neue',Arial,sans-serif; }
.receipt-head { background:#0f1b35; color:#fff; text-align:center; padding:30px 24px 22px; position:relative; }
.receipt-logo { font-size:20px; font-weight:800; letter-spacing:.14em; line-height:1.2; }
.receipt-sub  { font-size:10px; opacity:.55; letter-spacing:.18em; text-transform:uppercase; margin-top:5px; }
.receipt-head-badge {
	position:absolute; top:12px; right:14px;
	font-size:9px; font-weight:800; letter-spacing:.14em; text-transform:uppercase;
	padding:3px 8px; border-radius:3px; background:rgba(255,255,255,.15); color:#fff;
}
.receipt-head-badge.paid     { background:#2e7d32; }
.receipt-head-badge.terminal { background:#6c757d; }

.receipt-amount-block { text-align:center; padding:28px 20px 22px; background:#f7f8fa; border-bottom:2px dashed #e0e4ec; }
.receipt-amount-label { font-size:10px; font-weight:700; letter-spacing:.16em; text-transform:uppercase; color:#aaa; margin-bottom:8px; }
.receipt-amount-value { font-size:44px; font-weight:900; color:#0f1b35; line-height:1; letter-spacing:-.01em; }
.receipt-amount-block.paid .receipt-amount-label { color:#2e7d32; }

.receipt-qr-block { text-align:center; padding:26px 20px 10px; }
.receipt-qr-block img { width:210px; height:210px; display:block; margin:0 auto; image-rendering:pixelated; border:1px solid #eee; border-radius:4px; padding:6px; background:#fff; }
.receipt-qr-no { text-align:center; padding:20px; color:#aaa; font-size:12px; }

.receipt-paid-panel {
	text-align:center; padding:30px 24px 22px;
}
.receipt-paid-panel .paid-check {
	display:inline-flex; align-items:center; justify-content:center;
	width:72px; height:72px; border-radius:50%;
	background:#2e7d32; color:#fff; font-size:38px; font-weight:700;
	margin:0 auto 12px; box-shadow:0 0 0 6px rgba(46,125,50,.12);
}
.receipt-paid-panel .paid-title { font-size:18px; font-weight:800; color:#2e7d32; letter-spacing:.04em; text-transform:uppercase; }
.receipt-paid-panel .paid-when  { font-size:11px; color:#6c757d; margin-top:4px; }

.receipt-term-panel { text-align:center; padding:30px 24px 22px; color:#6c757d; }
.receipt-term-panel .term-icon {
	display:inline-flex; align-items:center; justify-content:center;
	width:60px; height:60px; border-radius:50%;
	background:#e9ecef; color:#6c757d; font-size:30px; font-weight:700;
	margin:0 auto 10px;
}
.receipt-term-panel .term-label { font-size:14px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }

.receipt-hint { text-align:center; font-size:12px; color:#777; padding:12px 30px 22px; line-height:1.65; }

.receipt-actions { text-align:center; padding:0 20px 16px; }
.receipt-actions .btn-receipt-check {
	width:100%; font-size:12px; font-weight:600;
	letter-spacing:.04em; text-transform:uppercase;
	padding:10px 14px; border-radius:6px;
	/* Явно отменяем возможный Bootstrap .btn-check: position/clip/pointer-events */
	position:static; clip:auto; pointer-events:auto;
}

.receipt-divider { border:none; border-top:2px dashed #e0e4ec; margin:0 20px; }
.receipt-meta { padding:16px 24px 6px; }
.receipt-meta-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
.receipt-meta-row .rm-label { font-size:10px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#aaa; }
.receipt-meta-row .rm-value { font-size:12px; font-family:monospace; color:#333; text-align:right; word-break:break-all; max-width:220px; }
.receipt-bottom { text-align:center; padding:10px 20px 22px; }
.receipt-tagline { display:inline-block; font-size:10px; color:#bbb; letter-spacing:.08em; }

/* Inline-обёртка (когда чек встроен в страницу, а не в модалку) */
.receipt-inline-frame {
	max-width:380px; margin:0 auto; border-radius:14px; overflow:hidden;
	background:#fff; box-shadow:0 8px 40px rgba(0,0,0,.12); border:1px solid #eef0f4;
}
</style>

<?php
add_action( 'wp_footer', function () {
?>
<script>
(function ($) {
	'use strict';

	// ── Конфиг + текущий контекст ─────────────────────────────────────────────

	var CFG = { ajaxUrl: null, nonce: null };
	var CTX = null;   // { data, onStatusChange, $container }

	function configure(opts) {
		opts = opts || {};
		if (opts.ajaxUrl) CFG.ajaxUrl = opts.ajaxUrl;
		if (opts.nonce)   CFG.nonce   = opts.nonce;
	}

	// ── Утилиты ───────────────────────────────────────────────────────────────

	function esc(str) {
		if (str === null || str === undefined) return '';
		return String(str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function toast(msg, type) {
		if (window.MalibuToast && typeof window.MalibuToast.show === 'function') {
			window.MalibuToast.show(msg, type);
		}
	}

	function formatRub(val) {
		if (val === null || val === undefined || val === '') return '—';
		var n = parseFloat(val);
		if (isNaN(n)) return '—';
		var parts = n.toFixed(2).split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
		return parts[0] + ',' + parts[1] + '\u00a0₽';
	}

	function formatDateRu(dt) {
		if (!dt) return '—';
		var d = String(dt).split(' ')[0];
		var p = d.split('-');
		if (p.length !== 3) return esc(dt);
		return p[2] + '.' + p[1] + '.' + p[0];
	}

	function formatDateTimeRu(dt) {
		if (!dt) return '—';
		var parts = String(dt).split(' ');
		var d = parts[0], t = parts[1] || '';
		var p = d.split('-');
		if (p.length !== 3) return esc(dt);
		var out = p[2] + '.' + p[1] + '.' + p[0];
		return t ? out + ' ' + t.slice(0, 5) : out;
	}

	var OPEN_STATUSES     = ['created', 'pending'];
	var TERMINAL_LABELS   = {
		paid:      'Оплачено',
		declined:  'Отклонено',
		cancelled: 'Отменено',
		expired:   'Истекло',
		error:     'Ошибка',
	};

	// ── Рендер ────────────────────────────────────────────────────────────────

	function render(data) {
		data = data || {};
		var status   = (data.status_code || 'created').toLowerCase();
		var isOpen   = OPEN_STATUSES.indexOf(status) !== -1;
		var isPaid   = status === 'paid';
		var dateStr  = formatDateRu(data.created_at);

		var headBadge = '';
		if (isPaid) {
			headBadge = '<span class="receipt-head-badge paid">Оплачено</span>';
		} else if (!isOpen) {
			headBadge = '<span class="receipt-head-badge terminal">' + esc(TERMINAL_LABELS[status] || status) + '</span>';
		}

		// Блок суммы — меняем label в зависимости от состояния
		var amountLabel = isPaid ? 'Получено' : 'К оплате';
		var amountValue = (data.payment_amount_value !== null && data.payment_amount_value !== undefined)
			? formatRub(data.payment_amount_value) : '—';

		// Центральный блок: QR / Paid / Terminal
		var centerBlock = '';
		if (isPaid) {
			centerBlock = '<div class="receipt-paid-panel">'
				+ '<div class="paid-check">✓</div>'
				+ '<div class="paid-title">Платёж подтверждён</div>'
				+ (data.paid_at ? '<div class="paid-when">' + esc(formatDateTimeRu(data.paid_at)) + '</div>' : '')
				+ '</div>';
		} else if (!isOpen) {
			centerBlock = '<div class="receipt-term-panel">'
				+ '<div class="term-icon">!</div>'
				+ '<div class="term-label">' + esc(TERMINAL_LABELS[status] || status) + '</div>'
				+ '</div>';
		} else {
			centerBlock = '<div class="receipt-qr-block">'
				+ (data.qr_url
					? '<img src="' + esc(data.qr_url) + '" alt="QR-код для оплаты">'
					: '<div class="receipt-qr-no">QR-код недоступен</div>')
				+ '</div>'
				+ '<div class="receipt-hint">'
				+   'Отсканируйте QR-код камерой телефона<br>'
				+   'и переведите <strong>точную сумму</strong> по реквизитам'
				+ '</div>';
		}

		// Кнопка «Проверить» — только для открытых статусов и если есть id + конфиг
		var actionsBlock = '';
		if (isOpen && data.id && CFG.ajaxUrl && CFG.nonce) {
			actionsBlock = '<div class="receipt-actions">'
				+ '<button type="button" class="btn btn-primary btn-receipt-check" data-id="' + esc(data.id) + '">'
				+ '<i class="pg-icon m-r-5">tick_circle</i>Проверить оплату'
				+ '</button>'
				+ '</div>';
		}

		return '<div class="receipt-wrap" data-status="' + esc(status) + '">'
			+ '<div class="receipt-head">'
			+   headBadge
			+   '<div class="receipt-logo">MALIBU EXCHANGE</div>'
			+   '<div class="receipt-sub">Платёжный счёт</div>'
			+ '</div>'
			+ '<div class="receipt-amount-block' + (isPaid ? ' paid' : '') + '">'
			+   '<div class="receipt-amount-label">' + esc(amountLabel) + '</div>'
			+   '<div class="receipt-amount-value">' + esc(amountValue) + '</div>'
			+ '</div>'
			+ centerBlock
			+ actionsBlock
			+ '<hr class="receipt-divider">'
			+ '<div class="receipt-meta">'
			+   '<div class="receipt-meta-row"><span class="rm-label">Заказ</span><span class="rm-value">' + esc(data.merchant_order_id || '—') + '</span></div>'
			+   '<div class="receipt-meta-row"><span class="rm-label">Дата</span><span class="rm-value">' + esc(dateStr) + '</span></div>'
			+ '</div>'
			+ '<div class="receipt-bottom">'
			+   '<span class="receipt-tagline">malibu.exchange</span>'
			+ '</div>'
			+ '</div>';
	}

	// ── Модалка ───────────────────────────────────────────────────────────────

	var _modal = null;
	function getModal() {
		if (!_modal) { _modal = new bootstrap.Modal(document.getElementById('order-receipt-modal')); }
		return _modal;
	}

	function showModal(data, opts) {
		CTX = { data: data || {}, onStatusChange: (opts && opts.onStatusChange) || null, $container: $('#order-receipt-body') };
		$('#order-receipt-body').html(render(data));
		getModal().show();
	}

	function showModalLoading() {
		CTX = null;
		$('#order-receipt-body').html('<div class="text-center p-t-30 p-b-30 text-muted">Загрузка…</div>');
		getModal().show();
	}

	function showModalError(msg) {
		CTX = null;
		$('#order-receipt-body').html('<div class="text-center text-danger p-t-20 p-b-20">' + esc(msg || 'Ошибка') + '</div>');
		getModal().show();
	}

	function renderInto($el, data, opts) {
		var $c = $($el);
		CTX = { data: data || {}, onStatusChange: (opts && opts.onStatusChange) || null, $container: $c };
		$c.html(render(data));
	}

	// ── Клик по «Проверить оплату» ────────────────────────────────────────────

	$(document).on('click', '.btn-receipt-check', function (e) {
		e.preventDefault();
		e.stopPropagation();
		if (!CFG.ajaxUrl || !CFG.nonce) {
			toast('Не сконфигурирован AJAX-контекст проверки.', 'danger');
			return;
		}
		if (!CTX) {
			toast('Потерян контекст чека. Перезагрузите страницу.', 'danger');
			return;
		}

		var $btn = $(this);
		var id   = $btn.data('id') || (CTX.data && CTX.data.id);
		if (!id) return;
		if ($btn.prop('disabled')) return;

		$btn.prop('disabled', true).html('<i class="pg-icon m-r-5">refresh</i>Проверяем…');

		$.post(CFG.ajaxUrl, {
			action: 'me_orders_check_status',
			_nonce: CFG.nonce,
			id:     id,
			intent: 'check',
		})
		.done(function (res) {
			if (!res || !res.success) {
				toast((res && res.data && res.data.message) ? res.data.message : 'Ошибка проверки.', 'danger');
				$btn.prop('disabled', false).html('<i class="pg-icon m-r-5">tick_circle</i>Проверить оплату');
				return;
			}
			var ns   = res.data.new_status;
			var type = ns === 'paid' ? 'success'
				: (['declined','cancelled','expired','error'].indexOf(ns) !== -1) ? 'warning'
				: 'info';
			toast(res.data.message || 'Статус обновлён.', type);

			if (res.data.changed) {
				// Перерисовываем чек с новым статусом локально
				var newData = $.extend({}, CTX.data, {
					status_code: ns,
					paid_at:     ns === 'paid' ? (new Date().toISOString().slice(0,19).replace('T', ' ')) : CTX.data.paid_at,
				});
				CTX.data = newData;
				CTX.$container.html(render(newData));

				if (typeof CTX.onStatusChange === 'function') {
					CTX.onStatusChange({
						id:         id,
						old_status: res.data.old_status,
						new_status: ns,
						message:    res.data.message,
						original:   res.data,
					});
				}
			} else {
				$btn.prop('disabled', false).html('<i class="pg-icon m-r-5">tick_circle</i>Проверить оплату');
			}
		})
		.fail(function () {
			toast('Сетевая ошибка при проверке статуса.', 'danger');
			$btn.prop('disabled', false).html('<i class="pg-icon m-r-5">tick_circle</i>Проверить оплату');
		});
	});

	window.MalibuOrderReceipt = {
		configure:        configure,
		render:           render,
		renderInto:       renderInto,
		showModal:        showModal,
		showModalLoading: showModalLoading,
		showModalError:   showModalError,
	};

}(jQuery));
</script>
<?php
}, 98 );
?>

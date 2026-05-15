<?php
/**
 * Общий host для toast-уведомлений.
 *
 * Подключается через get_template_part('template-parts/toast-host').
 *
 * Выставляет в window:
 *   MalibuToast.show(msg, type='info')  — type ∈ success|info|warning|danger
 *
 * По умолчанию использует нативный Pages pgNotification из референсной темы.
 * Самописный toast остаётся только как fallback, если Pages JS не загружен.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'MALIBU_TOAST_HOST_LOADED' ) ) {
	return;
}
define( 'MALIBU_TOAST_HOST_LOADED', true );
?>

<style>
/* Fallback toast, используется только если нативный Pages pgNotification
 * недоступен на странице.
 */
#malibu-toast-host {
	position:fixed; top:24px; right:24px; z-index:1080;
	display:flex; flex-direction:column; gap:10px;
	pointer-events:none; max-width:380px;
}
.malibu-toast {
	pointer-events:auto;
	display:flex; align-items:flex-start; gap:10px;
	min-width:280px; padding:12px 14px;
	background:#fff; border:1px solid #e5e7eb; border-left:4px solid #6c757d;
	border-radius:6px; box-shadow:0 6px 24px rgba(15,27,53,.14);
	font-size:13px; color:#1f2937; line-height:1.45;
	opacity:0; transform:translateY(-8px);
	transition:opacity .25s ease, transform .25s ease;
}
.malibu-toast.show      { opacity:1; transform:translateY(0); }
.malibu-toast.hide      { opacity:0; transform:translateY(-8px); }
.malibu-toast .tst-icon { flex:0 0 auto; width:20px; height:20px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; color:#fff; font-size:12px; font-weight:700; margin-top:1px; }
.malibu-toast .tst-msg  { flex:1 1 auto; word-wrap:break-word; }
.malibu-toast.toast-success { border-left-color:#2e7d32; }
.malibu-toast.toast-success .tst-icon { background:#2e7d32; }
.malibu-toast.toast-info    { border-left-color:#1565c0; }
.malibu-toast.toast-info    .tst-icon { background:#1565c0; }
	.malibu-toast.toast-warning { border-left-color:#FF8F00; }
	.malibu-toast.toast-warning .tst-icon { background:#FF8F00; }
	.malibu-toast.toast-danger  { border-left-color:#c62828; }
	.malibu-toast.toast-danger  .tst-icon { background:#c62828; }
	</style>

	<div class="modal fade" id="malibu-confirm-modal" tabindex="-1" role="dialog" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-sm">
			<div class="modal-content">
				<div class="modal-header clearfix text-left">
					<button aria-label="Закрыть" type="button" class="close" data-bs-dismiss="modal" aria-hidden="true">
						<i class="pg-icon">close</i>
					</button>
					<h5 class="modal-title" id="malibu-confirm-title">Подтвердите действие</h5>
				</div>
				<div class="modal-body">
					<p class="no-margin" id="malibu-confirm-message"></p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-default" data-bs-dismiss="modal" id="malibu-confirm-cancel">Отмена</button>
					<button type="button" class="btn btn-primary" id="malibu-confirm-ok">Подтвердить</button>
				</div>
			</div>
		</div>
	</div>

<?php
add_action( 'wp_footer', function () {
?>
<script>
(function ($) {
	'use strict';

		var TITLES = {
			success: 'Успешно',
			info:    'Информация',
			warning: 'Внимание',
			danger:  'Ошибка'
		};
		var ICONS = { success:'✓', info:'i', warning:'!', danger:'×' };
		var confirmCallback = null;
		var confirmModal = null;

	function esc(str) {
		if (str === null || str === undefined) return '';
		return String(str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function normalizeType(type) {
		type = String(type || 'info').toLowerCase();
		if (type === 'error') return 'danger';
		if (type === 'warn') return 'warning';
		if (type !== 'success' && type !== 'info' && type !== 'warning' && type !== 'danger') {
			return 'info';
		}
		return type;
	}

	function resolveContainer() {
		var $container = $('.page-content-wrapper').first();
		return $container.length ? $container : $('body');
	}

	function showNative(msg, type) {
		type = normalizeType(type);
		if (typeof $.fn.pgNotification !== 'function') {
			return false;
		}

		resolveContainer().pgNotification({
			style: 'simple',
			position: 'top-right',
			type: type,
			showClose: true,
			timeout: 4500,
			message:
				'<div class="d-flex flex-column">' +
					'<div class="semi-bold m-b-5">' + esc(TITLES[type] || 'Уведомление') + '</div>' +
					'<div>' + esc(msg) + '</div>' +
				'</div>'
		}).show();

		return true;
	}

	function ensureFallbackHost() {
		var $host = $('#malibu-toast-host');
		if ($host.length === 0) {
			$host = $('<div id="malibu-toast-host"></div>').appendTo('body');
		}
		return $host;
	}

	function showFallback(msg, type) {
		type = normalizeType(type);
		var $host = ensureFallbackHost();
		var $t = $(
			'<div class="malibu-toast toast-' + type + '" role="status" aria-live="polite">'
			+ '<span class="tst-icon">' + esc(ICONS[type] || 'i') + '</span>'
			+ '<span class="tst-msg">' + esc(msg) + '</span>'
			+ '</div>'
		);
		$host.append($t);
		requestAnimationFrame(function () { $t.addClass('show'); });
		setTimeout(function () { $t.removeClass('show').addClass('hide'); }, 3400);
		setTimeout(function () { $t.remove(); }, 3800);
	}

		function show(msg, type) {
			if (msg === null || msg === undefined || msg === '') return;
			if (showNative(msg, type)) return;
			showFallback(msg, type);
		}

		function getConfirmModal() {
			var node = document.getElementById('malibu-confirm-modal');
			if (!node) {
				return null;
			}
			if (window.bootstrap && bootstrap.Modal) {
				if (!confirmModal) {
					confirmModal = new bootstrap.Modal(node);
				}
				return confirmModal;
			}
			return {
				show: function () { $('#malibu-confirm-modal').modal('show'); },
				hide: function () { $('#malibu-confirm-modal').modal('hide'); }
			};
		}

		function showConfirm(message, callback, opts) {
			opts = opts || {};
			var modal = getConfirmModal();
			if (!modal) {
				show('Не удалось открыть окно подтверждения. Обновите страницу и повторите действие.', 'danger');
				return;
			}

			confirmCallback = typeof callback === 'function' ? callback : null;
			$('#malibu-confirm-title').text(opts.title || 'Подтвердите действие');
			$('#malibu-confirm-message').text(message || '');
			$('#malibu-confirm-ok')
				.removeClass('btn-primary btn-success btn-warning btn-danger btn-complete')
				.addClass(opts.btnClass || 'btn-primary')
				.text(opts.btnText || 'Подтвердить');
			$('#malibu-confirm-cancel').text(opts.cancelText || 'Отмена');
			modal.show();
		}

		$(document).on('click', '#malibu-confirm-ok', function () {
			var cb = confirmCallback;
			confirmCallback = null;
			var modal = getConfirmModal();
			if (modal) {
				modal.hide();
			}
			if (cb) {
				cb();
			}
		});

		$(document).on('hidden.bs.modal', '#malibu-confirm-modal', function () {
			confirmCallback = null;
		});

		window.MalibuToast = { show: show };
		window.MalibuConfirm = { show: showConfirm };

	}(jQuery));
	</script>
<?php
}, 98 );
?>

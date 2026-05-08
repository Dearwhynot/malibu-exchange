<?php
/*
Template Name: Logs Page
Slug: logs
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

if ( ! crm_can_access( 'logs.view' ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$nonce          = wp_create_nonce( 'me_logs_list' );
$_logs_org      = crm_require_company_page_context();
$tz_label       = crm_get_timezone_label( $_logs_org );

$filter_users_args = [
	'orderby' => 'user_login',
	'order'   => 'ASC',
	'fields'  => [ 'ID', 'user_login', 'display_name' ],
	'include' => [ -1 ],
];

$_log_user_ids = crm_get_company_user_ids( $_logs_org );
$_log_user_ids = array_values( array_filter( array_map( 'intval', $_log_user_ids ), fn( $id ) => $id !== 1 ) );
$filter_users_args['include'] = ! empty( $_log_user_ids ) ? $_log_user_ids : [ -1 ];

$filter_users = get_users( $filter_users_args );

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
							<li class="breadcrumb-item active">Журнал действий</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">

				<!-- ─── Фильтры ───────────────────────────────────────────────────── -->
				<div class="card card-default m-b-20">
					<div class="card-body p-t-20 p-b-15">

						<div class="row g-2 align-items-center m-b-10">
							<div class="col-12 col-md-4">
								<div class="input-group">
									<span class="input-group-text"><i class="pg-icon">search</i></span>
									<input type="search" id="f-search" class="form-control"
									       placeholder="Сообщение, пользователь, событие, IP…">
								</div>
							</div>
							<div class="col-6 col-md-2">
								<select id="f-category" class="full-width" data-init-plugin="select2">
									<option value="">Все категории</option>
									<option value="auth">auth</option>
									<option value="users">users</option>
									<option value="rates">rates</option>
									<option value="orders">orders</option>
									<option value="payments">payments</option>
									<option value="callbacks">callbacks</option>
									<option value="integrations">integrations</option>
									<option value="cron">cron</option>
									<option value="settings">settings</option>
									<option value="system">system</option>
									<option value="security">security</option>
									<option value="api">api</option>
								</select>
							</div>
							<div class="col-6 col-md-2">
								<select id="f-level" class="full-width" data-init-plugin="select2">
									<option value="">Все уровни</option>
									<option value="info">info</option>
									<option value="warning">warning</option>
									<option value="error">error</option>
									<option value="security">security</option>
								</select>
							</div>
							<div class="col-6 col-md-2">
								<input type="date" id="f-date-from" class="form-control" placeholder="Дата с">
							</div>
							<div class="col-6 col-md-2">
								<input type="date" id="f-date-to" class="form-control" placeholder="Дата по">
							</div>
						</div>

						<div class="row g-2 align-items-center">
							<div class="col-6 col-md-2">
								<select id="f-user-login" class="full-width" data-init-plugin="select2"
								        data-placeholder="Пользователь" data-allow-clear="true">
									<option value=""></option>
									<?php foreach ( $filter_users as $u ) : ?>
										<option value="<?php echo esc_attr( $u->user_login ); ?>">
											<?php
											echo esc_html( $u->user_login );
											if ( $u->display_name && $u->display_name !== $u->user_login ) {
												echo ' — ' . esc_html( $u->display_name );
											}
											?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-6 col-md-2">
								<select id="f-action" class="full-width" data-init-plugin="select2">
									<option value="">Все действия</option>
									<option value="login">login</option>
									<option value="logout">logout</option>
									<option value="create">create</option>
									<option value="update">update</option>
									<option value="delete">delete</option>
									<option value="password_change">password_change</option>
									<option value="status_change">status_change</option>
									<option value="role_change">role_change</option>
									<option value="snapshot">snapshot</option>
									<option value="callback">callback</option>
									<option value="reconcile">reconcile</option>
									<option value="expire">expire</option>
								</select>
							</div>
							<div class="col-6 col-md-2">
								<select id="f-target-type" class="full-width" data-init-plugin="select2">
									<option value="">Любой объект</option>
									<option value="user">user</option>
									<option value="rate">rate</option>
									<option value="market_snapshot">market_snapshot</option>
									<option value="settings">settings</option>
									<option value="role">role</option>
									<option value="payment_order">payment_order</option>
									<option value="company">company</option>
									<option value="company_office">company_office</option>
								</select>
							</div>
							<div class="col-6 col-md-2">
								<select id="f-is-success" class="full-width" data-init-plugin="select2">
									<option value="">Любой статус</option>
									<option value="1">Успешно</option>
									<option value="0">Ошибка</option>
								</select>
							</div>
							<div class="col-4 col-md-1">
								<select id="f-per-page" class="full-width" data-init-plugin="select2">
									<option value="25">25</option>
									<option value="50">50</option>
									<option value="100">100</option>
								</select>
							</div>
							<div class="col-8 col-md-3 d-flex gap-2 justify-content-end">
								<button type="button" id="btn-logs-search" class="btn btn-primary">
									<i class="pg-icon">search</i> Найти
								</button>
								<button type="button" id="btn-logs-reset" class="btn btn-default">
									Сброс
								</button>
							</div>
						</div>

					</div>
				</div>

				<!-- ─── Счётчик ────────────────────────────────────────────────────── -->
				<div class="d-flex justify-content-between align-items-center m-b-10">
					<div id="logs-stats" class="text-muted small"></div>
					<div class="d-flex align-items-center gap-2">
						<span class="text-muted small" title="Часовой пояс отображения дат">
							<i class="pg-icon" style="font-size:13px;vertical-align:middle">time</i>
							<?php echo esc_html( $tz_label ); ?>
						</span>
						<div id="logs-loading" class="text-muted small d-none">
							<span class="pg-icon" style="animation:spin 1s linear infinite;display:inline-block;">refresh</span>
							Загрузка…
						</div>
					</div>
				</div>

				<!-- ─── Таблица ────────────────────────────────────────────────────── -->
				<div class="card card-default">
					<div class="card-body p-0">
						<div class="table-responsive">
							<table class="table table-hover m-b-0" id="logs-table">
								<thead>
									<tr>
										<th style="width:140px">Дата / Время</th>
										<th style="width:90px">Категория</th>
										<th style="width:130px">Событие</th>
										<th style="width:80px">Уровень</th>
										<th style="width:110px">Пользователь</th>
										<th style="width:130px">Объект</th>
										<th>Описание</th>
										<th style="width:105px">IP</th>
										<th style="width:75px">Статус</th>
										<th style="width:40px"></th>
									</tr>
								</thead>
								<tbody id="logs-tbody">
									<tr>
										<td colspan="10" class="text-center p-t-30 p-b-30 text-muted">
											Нажмите «Найти» для загрузки данных.
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<!-- ─── Пагинация ──────────────────────────────────────────────────── -->
				<div id="logs-pagination" class="d-flex justify-content-between align-items-center m-t-15 m-b-30"></div>

			</div><!-- /.container-fluid -->
		</div>

		<?php get_template_part( 'template-parts/footer-backoffice' ); ?>
	</div>
</div>

<!-- ─── Модальное окно деталей ────────────────────────────────────────────── -->
<div class="modal fade" id="log-detail-modal" tabindex="-1" role="dialog"
     aria-labelledby="log-detail-title" aria-hidden="true">
	<div class="modal-dialog modal-lg" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title" id="log-detail-title">Детали события</h4>
				<button type="button" class="close" data-bs-dismiss="modal" aria-label="Закрыть">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="modal-body" id="log-detail-body">
				<div class="text-center p-t-20 p-b-20">
					<span class="text-muted">Загрузка…</span>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-bs-dismiss="modal">Закрыть</button>
			</div>
		</div>
	</div>
</div>

<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>

<?php
add_action( 'wp_footer', function () use ( $nonce ) {
?>
<style>
@keyframes spin { to { transform: rotate(360deg); } }
#logs-table th { white-space: nowrap; font-size: 12px; color: #6c757d; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; border-top: none; }
#logs-table td { vertical-align: middle; font-size: 13px; }
.log-level-badge { display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 11px; font-weight: 600; letter-spacing: .03em; }
.log-level-info     { background: #e8f4fd; color: #2196F3; }
.log-level-warning  { background: #fff8e1; color: #FF8F00; }
.log-level-error    { background: #fde8e8; color: #e53935; }
.log-level-security { background: #3d0a0a; color: #ff6b6b; }
.log-cat-badge { display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 11px; background: #f1f3f4; color: #495057; }
.log-message { max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.log-detail-grid { display: grid; grid-template-columns: 140px 1fr; gap: 6px 12px; }
.log-detail-label { font-weight: 600; color: #6c757d; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; align-self: start; padding-top: 2px; }
.log-detail-value { word-break: break-word; }
.log-context-pre { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 10px 14px; font-size: 12px; font-family: monospace; max-height: 300px; overflow-y: auto; white-space: pre; }
.btn-log-details { padding: 2px 8px; font-size: 11px; }
</style>

<script>
(function ($) {
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCE    = '<?php echo esc_js( $nonce ); ?>';

	var currentPage = 1;
	var totalPages  = 1;
	var totalRows   = 0;

	// ── Утилиты ──────────────────────────────────────────────────────────────

	function escHtml(str) {
		if (str === null || str === undefined) return '';
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function levelBadge(level) {
		return '<span class="log-level-badge log-level-' + escHtml(level) + '">' + escHtml(level) + '</span>';
	}

	function catBadge(cat) {
		return '<span class="log-cat-badge">' + escHtml(cat) + '</span>';
	}

	function successBadge(ok) {
		if (ok) return '<span class="badge badge-success">OK</span>';
		return '<span class="badge badge-danger">Fail</span>';
	}

	function formatDate(dt) {
		if (!dt) return '—';
		// dt = "2026-04-13 14:05:22"
		var parts = dt.split(' ');
		return '<span class="text-muted" style="font-size:11px">' + escHtml(parts[0]) + '</span><br>'
			+ '<strong>' + escHtml(parts[1] || '') + '</strong>';
	}

	function getFilters() {
		return {
			search:      $('#f-search').val(),
			category:    $('#f-category').val(),
			level:       $('#f-level').val(),
			log_action:  $('#f-action').val(),   // 'action' зарезервирован WordPress AJAX
			target_type: $('#f-target-type').val(),
			user_login:  $('#f-user-login').val(),
			date_from:   $('#f-date-from').val(),
			date_to:     $('#f-date-to').val(),
			is_success:  $('#f-is-success').val(),
			per_page:    $('#f-per-page').val(),
		};
	}

	// ── Загрузка данных ───────────────────────────────────────────────────────

	function fetchLogs(page) {
		$('#logs-loading').removeClass('d-none');
		$('#logs-tbody').html(
			'<tr><td colspan="10" class="text-center p-t-20 p-b-20 text-muted">Загрузка…</td></tr>'
		);
		$('#logs-pagination').html('');
		$('#logs-stats').html('');

		var filters = getFilters();
		currentPage = page || 1;

		$.post(AJAX_URL, $.extend({
			action:   'me_logs_list',
			_nonce:   NONCE,
			page:     currentPage,
			per_page: filters.per_page,
		}, filters))
		.done(function (res) {
			if (res.success) {
				totalRows  = res.data.total;
				totalPages = res.data.total_pages;
				renderTable(res.data.rows);
				renderStats(res.data.total, res.data.page, res.data.per_page);
				renderPagination(res.data.total_pages, res.data.page);
			} else {
				$('#logs-tbody').html(
					'<tr><td colspan="10" class="text-center text-danger p-t-20 p-b-20">'
					+ escHtml(res.data ? res.data.message : 'Ошибка') + '</td></tr>'
				);
			}
		})
		.fail(function () {
			$('#logs-tbody').html(
				'<tr><td colspan="10" class="text-center text-danger p-t-20 p-b-20">Сетевая ошибка. Попробуйте снова.</td></tr>'
			);
		})
		.always(function () {
			$('#logs-loading').addClass('d-none');
		});
	}

	// ── Рендер таблицы ────────────────────────────────────────────────────────

	function renderTable(rows) {
		if (!rows || rows.length === 0) {
			$('#logs-tbody').html(
				'<tr><td colspan="10" class="text-center p-t-30 p-b-30 text-muted">Записей не найдено.</td></tr>'
			);
			return;
		}

		var html = '';
		$.each(rows, function (i, r) {
			var targetCell = '—';
			if (r.target_type) {
				targetCell = '<span class="text-muted">' + escHtml(r.target_type) + '</span>';
				if (r.target_id) {
					targetCell += '<br><small class="text-muted">#' + escHtml(r.target_id) + '</small>';
				}
			}

			var userCell = r.user_login
				? escHtml(r.user_login)
				: '<span class="text-muted">—</span>';

			html += '<tr>'
				+ '<td>' + formatDate(r.created_at) + '</td>'
				+ '<td>' + catBadge(r.category) + '</td>'
				+ '<td style="font-size:11px;word-break:break-all">' + escHtml(r.event_code) + '</td>'
				+ '<td>' + levelBadge(r.level) + '</td>'
				+ '<td>' + userCell + '</td>'
				+ '<td>' + targetCell + '</td>'
				+ '<td><div class="log-message" title="' + escHtml(r.message) + '">' + escHtml(r.message) + '</div></td>'
				+ '<td style="font-size:11px">' + escHtml(r.ip_address || '—') + '</td>'
				+ '<td>' + successBadge(r.is_success) + '</td>'
				+ '<td><button class="btn btn-xs btn-default btn-log-details" data-id="' + escHtml(r.id) + '" title="Детали">…</button></td>'
				+ '</tr>';
		});

		$('#logs-tbody').html(html);
	}

	// ── Рендер счётчика ───────────────────────────────────────────────────────

	function renderStats(total, page, perPage) {
		var from = (page - 1) * perPage + 1;
		var to   = Math.min(page * perPage, total);
		if (total === 0) {
			$('#logs-stats').html('Ничего не найдено');
		} else {
			$('#logs-stats').html(
				'Показано ' + from + '–' + to + ' из ' + total + ' записей'
			);
		}
	}

	// ── Рендер пагинации ─────────────────────────────────────────────────────

	function renderPagination(pages, current) {
		if (pages <= 1) {
			$('#logs-pagination').html('');
			return;
		}

		var prev = current > 1
			? '<button class="btn btn-sm btn-default logs-page-btn" data-page="' + (current - 1) + '">‹ Назад</button>'
			: '<button class="btn btn-sm btn-default" disabled>‹ Назад</button>';

		var next = current < pages
			? '<button class="btn btn-sm btn-default logs-page-btn m-l-5" data-page="' + (current + 1) + '">Вперёд ›</button>'
			: '<button class="btn btn-sm btn-default m-l-5" disabled>Вперёд ›</button>';

		// Compact page numbers (show at most 7)
		var nums = '';
		var start = Math.max(1, current - 3);
		var end   = Math.min(pages, current + 3);
		if (start > 1) nums += '<button class="btn btn-sm btn-default logs-page-btn m-l-2" data-page="1">1</button>';
		if (start > 2) nums += '<span class="m-l-5 m-r-5 text-muted">…</span>';
		for (var p = start; p <= end; p++) {
			nums += '<button class="btn btn-sm m-l-2 logs-page-btn '
				+ (p === current ? 'btn-primary' : 'btn-default')
				+ '" data-page="' + p + '">' + p + '</button>';
		}
		if (end < pages - 1) nums += '<span class="m-l-5 m-r-5 text-muted">…</span>';
		if (end < pages) nums += '<button class="btn btn-sm btn-default logs-page-btn m-l-2" data-page="' + pages + '">' + pages + '</button>';

		$('#logs-pagination').html(
			'<div>' + prev + nums + next + '</div>'
			+ '<div class="text-muted small">Страница ' + current + ' из ' + pages + '</div>'
		);
	}

	// ── Просмотр деталей ─────────────────────────────────────────────────────

	var _detailModal = null;
	function getDetailModal() {
		if (!_detailModal) {
			_detailModal = new bootstrap.Modal(document.getElementById('log-detail-modal'));
		}
		return _detailModal;
	}

	function showDetails(id) {
		$('#log-detail-body').html(
			'<div class="text-center p-t-20 p-b-20 text-muted">Загрузка…</div>'
		);
		getDetailModal().show();

		$.get(AJAX_URL, {
			action: 'me_logs_get',
			_nonce: NONCE,
			id:     id,
		})
		.done(function (res) {
			if (res.success) {
				renderDetails(res.data);
			} else {
				$('#log-detail-body').html(
					'<div class="text-center text-danger p-t-20 p-b-20">'
					+ escHtml(res.data ? res.data.message : 'Ошибка') + '</div>'
				);
			}
		})
		.fail(function () {
			$('#log-detail-body').html(
				'<div class="text-center text-danger p-t-20 p-b-20">Сетевая ошибка.</div>'
			);
		});
	}

	function renderDetails(d) {
		var contextHtml = '—';
		if (d.context_json) {
			try {
				var parsed = JSON.parse(d.context_json);
				contextHtml = '<pre class="log-context-pre">' + escHtml(JSON.stringify(parsed, null, 2)) + '</pre>';
			} catch (e) {
				contextHtml = '<pre class="log-context-pre">' + escHtml(d.context_json) + '</pre>';
			}
		}

		function row(label, value) {
			return '<div class="log-detail-label">' + escHtml(label) + '</div>'
				+ '<div class="log-detail-value">' + value + '</div>';
		}

		var html = '<div class="log-detail-grid">'
			+ row('ID',          '<code>#' + escHtml(d.id) + '</code>')
			+ row('Дата/Время',  escHtml(d.created_at))
			+ row('Событие',     '<code>' + escHtml(d.event_code) + '</code>')
			+ row('Категория',   catBadge(d.category))
			+ row('Уровень',     levelBadge(d.level))
			+ row('Действие',    escHtml(d.action) || '—')
			+ row('Пользователь', d.user_login
				? escHtml(d.user_login) + (d.user_id ? ' <span class="text-muted small">(#' + escHtml(d.user_id) + ')</span>' : '')
				: '—')
			+ row('Объект',      (d.target_type || '—') + (d.target_id ? ' <span class="text-muted small">#' + escHtml(d.target_id) + '</span>' : ''))
			+ row('Статус',      d.is_success ? '<span class="badge badge-success">Успешно</span>' : '<span class="badge badge-danger">Ошибка</span>')
			+ row('Описание',    escHtml(d.message) || '—')
			+ row('IP-адрес',    '<code>' + escHtml(d.ip_address || '—') + '</code>')
			+ row('User-Agent',  '<small class="text-muted">' + escHtml(d.user_agent || '—') + '</small>')
			+ row('Метод',       escHtml(d.method || '—'))
			+ row('URI',         '<code style="word-break:break-all">' + escHtml(d.request_uri || '—') + '</code>')
			+ row('Источник',    escHtml(d.source || '—'))
			+ row('Контекст',    contextHtml)
			+ '</div>';

		$('#log-detail-body').html(html);
	}

	// ── Обработчики событий ───────────────────────────────────────────────────

	$('#btn-logs-search').on('click', function () {
		currentPage = 1;
		fetchLogs(1);
	});

	$('#btn-logs-reset').on('click', function () {
		$('#f-search').val('');
		$('#f-date-from').val('');
		$('#f-date-to').val('');
		// Select2 — сброс через trigger('change') чтобы UI обновился
		$('#f-category, #f-level, #f-action, #f-target-type, #f-is-success, #f-user-login').val('').trigger('change');
		$('#f-per-page').val('25').trigger('change');
		currentPage = 1;
		fetchLogs(1);
	});

	// Поиск по Enter
	$('#f-search').on('keypress', function (e) {
		if (e.which === 13) { currentPage = 1; fetchLogs(1); }
	});

	// Пагинация
	$(document).on('click', '.logs-page-btn', function () {
		fetchLogs(parseInt($(this).data('page'), 10));
		$('html, body').animate({ scrollTop: $('#logs-table').offset().top - 20 }, 200);
	});

	// Детали записи
	$(document).on('click', '.btn-log-details', function () {
		showDetails($(this).data('id'));
	});

	// Автозагрузка при первом открытии страницы
	fetchLogs(1);

}(jQuery));
</script>
<?php
}, 99 );
?>

<?php get_footer(); ?>

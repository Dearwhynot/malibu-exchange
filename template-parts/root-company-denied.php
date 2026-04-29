<?php
/**
 * Root deny screen for company-scoped pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$theme_uri          = get_template_directory_uri();
$root_dashboard_url = function_exists( 'malibu_exchange_get_root_dashboard_url' )
	? malibu_exchange_get_root_dashboard_url()
	: home_url( '/root-dashboard/' );

status_header( 404 );
nocache_headers();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
	<meta http-equiv="content-type" content="text/html;charset=UTF-8" />
	<meta charset="utf-8" />
	<title>404 — Company Route Closed For Root | Malibu Exchange</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no" />
	<link href="<?php echo esc_url( $theme_uri . '/vendor/pages/assets/plugins/bootstrap/css/bootstrap.min.css' ); ?>" rel="stylesheet" type="text/css" />
	<link href="<?php echo esc_url( $theme_uri . '/vendor/pages/pages/css/pages.css' ); ?>" rel="stylesheet" type="text/css" />
</head>
<body class="fixed-header error-page">
	<div class="d-flex justify-content-center full-height full-width align-items-center">
		<div class="error-container text-center" style="max-width:640px">
			<h1 class="error-number">404</h1>
			<h2 class="semi-bold">Root не работает в company-scoped разделе</h2>
			<p class="p-b-10">
				Этот маршрут принадлежит обычному company-контру. Для root доступны только отдельные страницы с префиксом <strong>root-</strong>.
			</p>
			<div class="m-t-20">
				<a href="<?php echo esc_url( $root_dashboard_url ); ?>" class="btn btn-primary btn-cons">
					Открыть root dashboard
				</a>
			</div>
		</div>
	</div>

	<div class="pull-bottom sm-pull-bottom full-width d-flex align-items-center justify-content-center">
		<div class="error-container">
			<div class="error-container-innner">
				<div class="p-b-30 sm-p-b-20 d-flex align-items-center justify-content-center">
					<p class="small no-margin hint-text">
						Malibu Exchange — root и company-контуры разделены жёстко
					</p>
				</div>
			</div>
		</div>
	</div>

	<script src="<?php echo esc_url( $theme_uri . '/vendor/pages/assets/plugins/jquery/jquery-3.2.1.min.js' ); ?>" type="text/javascript"></script>
	<script src="<?php echo esc_url( $theme_uri . '/vendor/pages/pages/js/pages.js' ); ?>" type="text/javascript"></script>
</body>
</html>

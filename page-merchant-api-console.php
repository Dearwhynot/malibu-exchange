<?php
/*
Template Name: Merchant API Console Page
Slug: merchant-api-console
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$theme_uri      = get_template_directory_uri();
$theme_dir      = get_template_directory();
$css_rel        = '/assets/css/merchant-api-public.css';
$css_file       = $theme_dir . $css_rel;
$css_version    = file_exists( $css_file ) ? (string) filemtime( $css_file ) : null;
$spec_url       = function_exists( 'crm_get_merchant_api_spec_url' ) ? crm_get_merchant_api_spec_url() : trailingslashit( $theme_uri ) . 'docs/api/merchant/openapi.yaml';
$spec_file_path = function_exists( 'crm_merchant_api_openapi_file_path' ) ? crm_merchant_api_openapi_file_path() : $theme_dir . '/docs/api/merchant/openapi.yaml';
$spec_exists    = is_string( $spec_file_path ) && file_exists( $spec_file_path );
$docs_url       = function_exists( 'crm_get_merchant_api_docs_url' ) ? crm_get_merchant_api_docs_url() : home_url( '/merchant-api/' );
$console_url    = function_exists( 'crm_get_merchant_api_console_url' ) ? crm_get_merchant_api_console_url() : home_url( '/merchant-api-console/' );
$site_name      = trim( wp_strip_all_tags( get_bloginfo( 'name', 'raw' ) ) );

wp_enqueue_style( 'merchant-api-swagger-ui', 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css', [], null );
wp_enqueue_style( 'merchant-api-public', $theme_uri . $css_rel, [ 'merchant-api-swagger-ui' ], $css_version );
wp_enqueue_script( 'merchant-api-swagger-bundle', 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js', [], null, true );
wp_enqueue_script( 'merchant-api-swagger-standalone', 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-standalone-preset.js', [ 'merchant-api-swagger-bundle' ], null, true );

$config = [
	'specUrl'     => esc_url_raw( $spec_url ),
	'containerId' => 'merchant-api-swagger',
];

wp_add_inline_script(
	'merchant-api-swagger-standalone',
	'(function(){var cfg=' . crm_json_for_inline_js( $config ) . ';function fail(message){var host=document.getElementById(cfg.containerId);if(host){host.innerHTML=\'<div class="merchant-api-public-error">\'+message+\'</div>\';}}document.addEventListener("DOMContentLoaded",function(){if(!cfg||!cfg.specUrl){fail("OpenAPI spec URL is not configured.");return;}if(!window.SwaggerUIBundle||!window.SwaggerUIStandalonePreset){fail("Swagger UI bundle did not load. Open the raw spec instead.");return;}var host=document.getElementById(cfg.containerId);if(!host){return;}window.ui=SwaggerUIBundle({url:cfg.specUrl,dom_id:"#"+cfg.containerId,deepLinking:true,displayRequestDuration:true,docExpansion:"list",filter:true,persistAuthorization:true,defaultModelsExpandDepth:1,layout:"BaseLayout",presets:[SwaggerUIBundle.presets.apis,SwaggerUIStandalonePreset]});});})();',
	'after'
);

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'merchant-api-public-body' ); ?>>
<?php wp_body_open(); ?>
<main class="merchant-api-public-page">
	<div class="merchant-api-public-shell">
		<div class="merchant-api-public-topbar">
			<a class="merchant-api-public-brand" href="<?php echo esc_url( $docs_url ); ?>">
				<span class="merchant-api-public-brand__mark">MX</span>
				<span class="merchant-api-public-brand__copy">
					<span class="merchant-api-public-brand__eyebrow"><?php echo esc_html( $site_name !== '' ? $site_name : 'Malibu Exchange' ); ?></span>
					<span class="merchant-api-public-brand__title">Merchant API Console</span>
				</span>
			</a>
			<nav class="merchant-api-public-nav" aria-label="Merchant API navigation">
				<a href="<?php echo esc_url( $docs_url ); ?>">Documentation</a>
				<a class="is-active" href="<?php echo esc_url( $console_url ); ?>">Console</a>
				<a href="<?php echo esc_url( $spec_url ); ?>" target="_blank" rel="noopener">OpenAPI YAML</a>
			</nav>
		</div>

		<section class="merchant-api-public-hero">
			<div class="merchant-api-public-hero__eyebrow">Interactive verification</div>
			<h1 class="merchant-api-public-hero__headline">Swagger UI console for manual Merchant API calls.</h1>
			<p class="merchant-api-public-hero__lead">
				Эта страница нужна для live-check и ручных запросов. Нажмите <strong>Authorize</strong> и вставьте raw token value. Для HTTP bearer scheme Swagger UI сам добавит префикс <code>Bearer</code>.
			</p>
			<div class="merchant-api-public-chip-row">
				<span class="merchant-api-public-chip">Try it out: <code>GET /merchant/me</code></span>
				<span class="merchant-api-public-chip">Idempotency: <code>external_order_id</code></span>
				<span class="merchant-api-public-chip">Spec source: <code>OpenAPI YAML</code></span>
			</div>
			<div class="merchant-api-public-actions">
				<a class="is-primary" href="<?php echo esc_url( $docs_url ); ?>">Read Reference Docs</a>
				<a class="is-secondary" href="<?php echo esc_url( $spec_url ); ?>" target="_blank" rel="noopener">Open Raw Spec</a>
			</div>
		</section>

		<div class="merchant-api-public-grid">
			<div class="merchant-api-public-kpi">
				<div class="merchant-api-public-kpi__label">Authorization</div>
				<div class="merchant-api-public-kpi__value">Use the <code>Authorize</code> button and paste the token value once.</div>
			</div>
			<div class="merchant-api-public-kpi">
				<div class="merchant-api-public-kpi__label">Create invoice modes</div>
				<div class="merchant-api-public-kpi__value"><code>orderAmount</code> for `USDT`, <code>paymentAmount</code> for `RUB`.</div>
			</div>
			<div class="merchant-api-public-kpi">
				<div class="merchant-api-public-kpi__label">Recommended first checks</div>
				<div class="merchant-api-public-kpi__value"><code>/merchant/me</code>, <code>/merchant/rates</code>, <code>/merchant/invoices</code>.</div>
			</div>
		</div>

		<section class="merchant-api-public-card">
			<div class="merchant-api-public-note">
				<div>
					<strong>Практика для интегратора:</strong> сначала проверьте <code>/merchant/me</code>, затем `rates`, и только после этого идите в `POST /merchant/invoices` с contour-aware `requested_amount.currency_code`.
				</div>
				<div>
					Spec URL: <code><?php echo esc_html( wp_parse_url( $spec_url, PHP_URL_PATH ) ?: $spec_url ); ?></code>
				</div>
			</div>
			<div id="merchant-api-swagger" class="merchant-api-public-host">
				<?php if ( ! $spec_exists ) : ?>
					<div class="merchant-api-public-error">OpenAPI spec file is missing on the server. The console page cannot render until <code>docs/api/merchant/openapi.yaml</code> is deployed.</div>
				<?php endif; ?>
			</div>
		</section>

		<p class="merchant-api-public-footer-note">
			Если Swagger UI не загрузился, откройте raw spec напрямую и используйте любой YAML-aware API client.
		</p>
	</div>
</main>
<?php wp_footer(); ?>
</body>
</html>

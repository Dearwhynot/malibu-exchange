<?php
/*
Template Name: Merchant API Docs Page
Slug: merchant-api
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

wp_enqueue_style( 'merchant-api-public', $theme_uri . $css_rel, [], $css_version );
wp_enqueue_script( 'merchant-api-redoc', 'https://cdn.jsdelivr.net/npm/redoc@2/bundles/redoc.standalone.js', [], null, true );

$config = [
	'specUrl'     => esc_url_raw( $spec_url ),
	'containerId' => 'merchant-api-redoc',
];

wp_add_inline_script(
	'merchant-api-redoc',
	'(function(){var cfg=' . crm_json_for_inline_js( $config ) . ';function fail(message){var host=document.getElementById(cfg.containerId);if(host){host.innerHTML=\'<div class="merchant-api-public-error">\'+message+\'</div>\';}}function resolveHashTarget(host,hash){var headingLinks,i,headingTarget,sectionNodes,node,needle,href;if(!host||!hash||hash.charAt(0)!=="#"){return null;}headingLinks=host.querySelectorAll("h1 a[href],h2 a[href],h3 a[href],h4 a[href],h5 a[href]");for(i=0;i<headingLinks.length;i++){href=headingLinks[i].getAttribute("href")||"";if(href===hash||href.slice(-hash.length)===hash){headingTarget=headingLinks[i].closest("h1,h2,h3,h4,h5");return headingTarget||headingLinks[i];}}needle=hash.slice(1);sectionNodes=host.querySelectorAll("[id],[data-section-id]");for(i=0;i<sectionNodes.length;i++){node=sectionNodes[i];if(node.id===needle||node.getAttribute("data-section-id")===needle){return node;}}return null;}function alignScrollableAncestors(target){var node,targetRect,nodeRect,style;for(node=target&&target.parentElement;node&&node!==document.body;node=node.parentElement){style=window.getComputedStyle(node);if((style.overflowY==="auto"||style.overflowY==="scroll")&&node.scrollHeight>node.clientHeight){targetRect=target.getBoundingClientRect();nodeRect=node.getBoundingClientRect();node.scrollTop+=targetRect.top-nodeRect.top-16;}}}function syncHashTarget(host){var target=resolveHashTarget(host,window.location.hash||"");if(!target||typeof target.scrollIntoView!=="function"){return;}target.scrollIntoView({block:"start",inline:"nearest",behavior:"auto"});alignScrollableAncestors(target);}function bindHashSync(host){var observer,scheduleSync,patchHistory;if(!host||host.__merchantApiHashSyncBound){return;}host.__merchantApiHashSyncBound=true;scheduleSync=function(){window.setTimeout(function(){syncHashTarget(host);},40);window.setTimeout(function(){syncHashTarget(host);},220);window.setTimeout(function(){syncHashTarget(host);},480);};patchHistory=function(method){var original=window.history&&window.history[method];if(typeof original!=="function"||original.__merchantApiWrapped){return;}window.history[method]=function(){var result=original.apply(this,arguments);scheduleSync();return result;};window.history[method].__merchantApiWrapped=true;};patchHistory("pushState");patchHistory("replaceState");window.addEventListener("hashchange",scheduleSync);host.addEventListener("click",scheduleSync,true);if(typeof MutationObserver==="function"){observer=new MutationObserver(function(){scheduleSync();});observer.observe(host,{childList:true,subtree:true});window.setTimeout(function(){observer.disconnect();},4000);}scheduleSync();}document.addEventListener("DOMContentLoaded",function(){if(!cfg||!cfg.specUrl){fail("OpenAPI spec URL is not configured.");return;}if(!window.Redoc){fail("Redoc bundle did not load. Open the raw spec instead.");return;}var host=document.getElementById(cfg.containerId);if(!host){return;}bindHashSync(host);window.Redoc.init(cfg.specUrl,{scrollYOffset:0,hideDownloadButton:false,nativeScrollbars:true,pathInMiddlePanel:true,expandResponses:"200,201",requiredPropsFirst:true},host,function(){window.setTimeout(function(){syncHashTarget(host);},80);window.setTimeout(function(){syncHashTarget(host);},320);});});})();',
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
					<span class="merchant-api-public-brand__title">Merchant API</span>
				</span>
			</a>
			<nav class="merchant-api-public-nav" aria-label="Merchant API navigation">
				<a class="is-active" href="<?php echo esc_url( $docs_url ); ?>">Documentation</a>
				<a href="<?php echo esc_url( $console_url ); ?>">Console</a>
				<a href="<?php echo esc_url( $spec_url ); ?>" target="_blank" rel="noopener">OpenAPI YAML</a>
			</nav>
		</div>

		<section class="merchant-api-public-hero">
			<div class="merchant-api-public-hero__eyebrow">Public integration surface</div>
			<h1 class="merchant-api-public-hero__headline">Merchant-scoped API for invoices, balances, rates, orders and payouts.</h1>
			<p class="merchant-api-public-hero__lead">
				Этот reference рендерится из одного OpenAPI source of truth и отражает уже поднятый Merchant API `v1`. Для `RUB_USDT` здесь явно зафиксированы оба company contours: `orderAmount` через `USDT` и `paymentAmount` через `RUB`.
			</p>
			<div class="merchant-api-public-chip-row">
				<span class="merchant-api-public-chip">Auth: <code>Bearer</code></span>
				<span class="merchant-api-public-chip">Namespace: <code>/wp-json/malibu/v1/merchant</code></span>
				<span class="merchant-api-public-chip">Source of truth: <code>OpenAPI 3.1</code></span>
			</div>
			<div class="merchant-api-public-actions">
				<a class="is-primary" href="<?php echo esc_url( $console_url ); ?>">Open Interactive Console</a>
				<a class="is-secondary" href="<?php echo esc_url( $spec_url ); ?>" target="_blank" rel="noopener">Download Raw Spec</a>
			</div>
		</section>

		<div class="merchant-api-public-grid">
			<div class="merchant-api-public-kpi">
				<div class="merchant-api-public-kpi__label">Base URL</div>
				<div class="merchant-api-public-kpi__value"><code>/wp-json/malibu/v1</code></div>
			</div>
			<div class="merchant-api-public-kpi">
				<div class="merchant-api-public-kpi__label">Invoice direction</div>
				<div class="merchant-api-public-kpi__value"><code>RUB_USDT</code> with two contour-dependent request modes</div>
			</div>
			<div class="merchant-api-public-kpi">
				<div class="merchant-api-public-kpi__label">Try it out</div>
				<div class="merchant-api-public-kpi__value">Use the <a href="<?php echo esc_url( $console_url ); ?>">Swagger UI console</a> for manual calls.</div>
			</div>
		</div>

		<section class="merchant-api-public-card">
			<div class="merchant-api-public-note">
				<div>
					<strong>Контрактная оговорка:</strong> company scope и merchant scope определяются только из Bearer token. Клиент не задаёт `company_id` или `merchant_id` как источник истины.
				</div>
				<div>
					Raw spec: <code><?php echo esc_html( wp_parse_url( $spec_url, PHP_URL_PATH ) ?: $spec_url ); ?></code>
				</div>
			</div>
			<div id="merchant-api-redoc" class="merchant-api-public-host">
				<?php if ( ! $spec_exists ) : ?>
					<div class="merchant-api-public-error">OpenAPI spec file is missing on the server. The docs page cannot render until <code>docs/api/merchant/openapi.yaml</code> is deployed.</div>
				<?php endif; ?>
			</div>
		</section>

		<p class="merchant-api-public-footer-note">
			Если визуальный рендер не загрузился из-за browser/CDN restrictions, используйте ссылку <a href="<?php echo esc_url( $spec_url ); ?>" target="_blank" rel="noopener">OpenAPI YAML</a> как резервный источник контракта.
		</p>
	</div>
</main>
<?php wp_footer(); ?>
</body>
</html>

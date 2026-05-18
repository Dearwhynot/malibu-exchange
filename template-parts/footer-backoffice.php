<?php
/**
 * Standard backoffice footer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$product_version      = function_exists( 'crm_get_product_release_version' ) ? crm_get_product_release_version() : '';
$release_notes_url    = function_exists( 'crm_get_product_release_notes_url' ) ? crm_get_product_release_notes_url() : '';
?>
<div class="container-fluid container-fixed-lg footer">
	<div class="copyright sm-text-center">
		<p class="small-text no-margin pull-left sm-pull-reset">
			<?php get_template_part( 'template-parts/footer-copyright-text' ); ?>
		</p>
		<?php if ( $product_version !== '' ) : ?>
			<p class="small-text no-margin pull-right sm-pull-reset">
				<?php if ( $release_notes_url !== '' ) : ?>
					<a href="<?php echo esc_url( $release_notes_url ); ?>" class="product-footer-version-link">
						Version <?php echo esc_html( $product_version ); ?>
					</a>
				<?php else : ?>
					Version <?php echo esc_html( $product_version ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<div class="clearfix"></div>
	</div>
</div>

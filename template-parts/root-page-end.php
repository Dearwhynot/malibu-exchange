<?php
/**
 * Root page shell end.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
			</div>
		</div>

		<div class="container-fluid container-fixed-lg footer">
			<div class="copyright sm-text-center">
				<p class="small-text no-margin pull-left sm-pull-reset">
					&copy;<?php echo esc_html( gmdate( 'Y' ) ); ?> Malibu Exchange. All Rights Reserved.
				</p>
				<div class="clearfix"></div>
			</div>
		</div>
	</div>
</div>

<?php
get_template_part( 'template-parts/quickview' );
get_template_part( 'template-parts/overlay' );
get_footer();

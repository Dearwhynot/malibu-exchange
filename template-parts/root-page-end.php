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

		<?php get_template_part( 'template-parts/footer-backoffice' ); ?>
	</div>
</div>

<?php
get_template_part( 'template-parts/quickview' );
get_template_part( 'template-parts/overlay' );
get_footer();

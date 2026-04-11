<?php
/*
Template Name: Default Page
Slug: default-page
*/
// slug страницы: default-page
?> 

<?php get_header(); ?>

<!-- BEGIN SIDEBPANEL-->
<!-- END SIDEBAR -->
<?php get_template_part('template-parts/sidebar'); ?>
<!-- END SIDEBPANEL-->

<!-- START PAGE-CONTAINER -->
<?php get_template_part('template-parts/default-page/page-container'); ?>
<!-- END PAGE CONTAINER -->

<!--START QUICKVIEW -->
<?php get_template_part('template-parts/quickview'); ?>
<!-- END QUICKVIEW-->

<!-- START OVERLAY -->
<?php get_template_part('template-parts/overlay'); ?>
<!-- END OVERLAY -->

<?php get_footer(); ?>

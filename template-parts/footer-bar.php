<?php
/**
 * Нижняя часть layout.
 *
 * Demo copyright заменен на нейтральный блок.
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="container-fluid container-fixed-lg footer">
    <div class="copyright sm-text-center">
        <p class="small-text no-margin pull-left sm-pull-reset">
            &copy; <?php echo esc_html(date('Y')); ?> <?php bloginfo('name'); ?>
            <span class="hint-text m-l-15">Malibu Exchange admin workspace</span>
        </p>
        <p class="small no-margin pull-right sm-pull-reset">
            Built on WordPress
        </p>
        <div class="clearfix"></div>
    </div>
</div>

<?php
$args = wp_parse_args($args ?? [], [
    'label' => 'Metric',
    'value' => '0',
]);
?>
<article class="me-card me-card--stat">
    <div class="me-stat__label"><?php echo esc_html($args['label']); ?></div>
    <div class="me-stat__value"><?php echo esc_html($args['value']); ?></div>
</article>

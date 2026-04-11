<?php

/**
 * Базовый fallback шаблон.
 *
 * Даже если ты еще не создал отдельные page templates,
 * тема не должна падать.
 */
if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<div class="content">
    <div class="container-fluid container-fixed-lg p-t-30">
        <div class="card card-default">
            <div class="card-body">
                <h1 class="m-t-0">Malibu Exchange</h1>
                <p class="m-b-0">Стартовый fallback шаблон темы.</p>
                <p class="hint-text m-t-10">Создай страницы на основе page-dashboard.php или своих копий.</p>
            </div>
        </div>
    </div>
</div>
<?php
get_footer();

die();
// wp_safe_redirect(home_url('/dashboard'));
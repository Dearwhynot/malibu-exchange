<?php
/*
Template Name: Rates
*/
malibu_exchange_require_login();
get_header();
?>
<div class="me-app-shell">
    <?php get_template_part('sidebar'); ?>
    <div class="me-main">
        <?php get_template_part('topbar'); ?>
        <main class="me-content">
            <div class="me-grid me-grid--2">
                <div class="me-card">
                    <h2>Manual rates</h2>
                    <form id="me-rates-form" class="me-form">
                        <label>USD → THB
                            <input type="text" name="usd_thb" value="35.90">
                        </label>
                        <label>RUB → THB
                            <input type="text" name="rub_thb" value="0.4210">
                        </label>
                        <label>USDT → THB
                            <input type="text" name="usdt_thb" value="35.70">
                        </label>
                        <button type="button" class="me-button">Save rates</button>
                    </form>
                </div>
                <div class="me-card">
                    <h2>Info</h2>
                    <p>Starter-only demo. Wire the button to admin-ajax, REST API or custom WP endpoints.</p>
                    <p>Keep automatic updates separate from manual override logic.</p>
                </div>
            </div>
        </main>
    </div>
</div>
<?php get_footer(); ?>

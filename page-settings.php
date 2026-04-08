<?php
/*
Template Name: Settings
Slug: settings
*/
malibu_exchange_require_login();
get_header();
?>
<div class="me-app-shell">
    <?php get_template_part('sidebar'); ?>
    <div class="me-main">
        <?php get_template_part('topbar'); ?>
        <main class="me-content">
            <div class="me-card">
                <h2>Settings</h2>
                <form class="me-form">
                    <label>Telegram bot token
                        <input type="password" value="">
                    </label>
                    <label>Default timezone
                        <input type="text" value="Asia/Bangkok">
                    </label>
                    <label>Default operator note
                        <textarea rows="4">Welcome to Malibu Exchange.</textarea>
                    </label>
                    <button type="button" class="me-button">Save settings</button>
                </form>
            </div>
        </main>
    </div>
</div>
<?php get_footer(); ?>

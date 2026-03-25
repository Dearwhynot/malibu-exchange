<header class="me-topbar">
    <div>
        <h1 class="me-page-title"><?php echo esc_html(malibu_exchange_page_title()); ?></h1>
        <div class="me-page-subtitle">Beach-inspired operator workspace for a simple Telegram exchange bot.</div>
    </div>
    <div class="me-topbar__right">
        <div class="me-chip">USD / RUB / THB</div>
        <div class="me-user">
            <div class="me-user__avatar"><?php echo esc_html(substr(wp_get_current_user()->display_name ?: 'U', 0, 1)); ?></div>
            <div>
                <div class="me-user__name"><?php echo esc_html(wp_get_current_user()->display_name ?: 'Operator'); ?></div>
                <div class="me-user__role">Backoffice</div>
            </div>
        </div>
    </div>
</header>

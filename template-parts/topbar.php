<?php
/**
 * Верхняя панель.
 *
 * Из оригинального demo здесь выброшены:
 * - notifications dropdown;
 * - search overlay triggers;
 * - лишние demo actions.
 *
 * Оставлен рабочий каркас, чтобы потом постепенно наращивать функционал.
 */
if (!defined('ABSPATH')) {
    exit;
}

$theme_uri = get_template_directory_uri();
$current_user = wp_get_current_user();
$display_name = $current_user && $current_user->exists() ? $current_user->display_name : 'Administrator';
?>
<div class="header">
    <a href="#" class="btn-link toggle-sidebar d-lg-none pg-icon btn-icon-link" data-toggle="sidebar">menu</a>

    <div>
        <div class="brand inline">
            <img
                src="<?php echo esc_url($theme_uri . '/assets/img/logo.png'); ?>"
                alt="logo"
                data-src="<?php echo esc_url($theme_uri . '/assets/img/logo.png'); ?>"
                data-src-retina="<?php echo esc_url($theme_uri . '/assets/img/logo_2x.png'); ?>"
                width="78"
                height="22"
            >
        </div>
    </div>

    <div class="d-flex align-items-center">
        <div class="dropdown pull-right d-lg-block d-none">
            <button class="profile-dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="profile dropdown">
                <span class="thumbnail-wrapper d32 circular inline">
                    <img
                        src="<?php echo esc_url($theme_uri . '/assets/img/profiles/avatar.jpg'); ?>"
                        alt=""
                        data-src="<?php echo esc_url($theme_uri . '/assets/img/profiles/avatar.jpg'); ?>"
                        data-src-retina="<?php echo esc_url($theme_uri . '/assets/img/profiles/avatar_small2x.jpg'); ?>"
                        width="32"
                        height="32"
                    >
                </span>
            </button>

            <div class="dropdown-menu dropdown-menu-right profile-dropdown" role="menu">
                <span class="dropdown-item">Signed in as <br><b><?php echo esc_html($display_name); ?></b></span>
                <div class="dropdown-divider"></div>
                <?php if (is_user_logged_in()) : ?>
                    <a href="<?php echo esc_url(admin_url()); ?>" class="dropdown-item">WP Admin</a>
                    <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>" class="dropdown-item">Logout</a>
                <?php else : ?>
                    <a href="<?php echo esc_url(wp_login_url()); ?>" class="dropdown-item">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

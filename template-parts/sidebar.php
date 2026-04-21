<?php

/**
 * Sidebar.
 *
 * Здесь оставлен уже НЕ demo-мусорный вариант.
 * Самое важное:
 * - структура Pages сохранена;
 * - ссылки переведены на WordPress-функции;
 * - меню сделано минимальным стартовым.
 *
 * Дальше ты можешь:
 * 1. либо вручную дописывать пункты;
 * 2. либо позже привязать полноценное WP menu.
 */
if (!defined('ABSPATH')) {
    exit;
}

$theme_uri = get_template_directory_uri();
$current_id = get_queried_object_id();
$vendor_img_uri = $theme_uri . '/vendor/pages/assets/img';
$is_root = is_user_logged_in() && function_exists('crm_is_root') && crm_is_root(get_current_user_id());
$dashboard_url = function_exists('malibu_exchange_get_dashboard_url') ? malibu_exchange_get_dashboard_url() : home_url('/dashboard/');
$company_dashboard_url = function_exists('malibu_exchange_get_company_dashboard_url') ? malibu_exchange_get_company_dashboard_url() : home_url('/dashboard/');
$root_dashboard_url = function_exists('malibu_exchange_get_root_dashboard_url') ? malibu_exchange_get_root_dashboard_url() : home_url('/root-dashboard/');
?>

<nav class="page-sidebar" data-pages="sidebar">
    <!-- BEGIN SIDEBAR MENU TOP TRAY CONTENT-->
    <div class="sidebar-overlay-slide from-top" id="appMenu">
        <div class="row">
            <div class="col-sm-6 no-padding">
                <a href="#" class="p-l-40"><img src="<?php echo esc_url($vendor_img_uri . '/demo/social_app.svg'); ?>" alt="socail">
                </a>
            </div>
            <div class="col-sm-6 no-padding">
                <a href="#" class="p-l-10"><img src="<?php echo esc_url($vendor_img_uri . '/demo/email_app.svg'); ?>" alt="socail">
                </a>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-6 m-t-20 no-padding">
                <a href="#" class="p-l-40"><img src="<?php echo esc_url($vendor_img_uri . '/demo/calendar_app.svg'); ?>" alt="socail">
                </a>
            </div>
            <div class="col-sm-6 m-t-20 no-padding">
                <a href="#" class="p-l-10"><img src="<?php echo esc_url($vendor_img_uri . '/demo/add_more.svg'); ?>" alt="socail">
                </a>
            </div>
        </div>
    </div>
    <!-- END SIDEBAR MENU TOP TRAY CONTENT-->
    <!-- BEGIN SIDEBAR MENU HEADER-->
    <div class="sidebar-header">
        <img src="<?php echo esc_url($vendor_img_uri . '/logo_white.png'); ?>" alt="logo" class="brand" data-src="<?php echo esc_url($vendor_img_uri . '/logo_white.png'); ?>" data-src-retina="<?php echo esc_url($vendor_img_uri . '/logo_white_2x.png'); ?>" width="78" height="22">
        <div class="sidebar-header-controls">
            <button aria-label="Toggle Drawer" type="button" class="btn btn-icon-link invert sidebar-slide-toggle m-l-20 m-r-10" data-pages-toggle="#appMenu">
                <i class="pg-icon">chevron_down</i>
            </button>
            <button aria-label="Pin Menu" type="button" class="btn btn-icon-link invert d-lg-inline-block d-xlg-inline-block d-md-inline-block d-sm-none d-none" data-toggle-pin="sidebar">
                <i class="pg-icon"></i>
            </button>
        </div>
    </div>
    <!-- END SIDEBAR MENU HEADER-->
    <!-- START SIDEBAR MENU -->
    <div class="sidebar-menu">
        <!-- BEGIN SIDEBAR MENU ITEMS-->
        <ul class="menu-items">
            <?php if ($is_root) : ?>
            <li class="m-t-20">
                <a href="<?php echo esc_url($root_dashboard_url); ?>">
                    <span class="title">Сводка</span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">chart_alt</i></span>
            </li>
            <li class="">
                <a href="<?php echo esc_url($company_dashboard_url); ?>">
                    <span class="title">Дашборд</span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">home</i></span>
            </li>
            <?php else : ?>
            <li class="m-t-20">
                <a href="<?php echo esc_url($dashboard_url); ?>">
                    <span class="title">Дашборд</span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">home</i></span>
            </li>
            <?php endif; ?>
            <li class="">
                <a href="<?php echo esc_url(home_url('/rates/')); ?>">
                    <span class="title">Курсы</span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">chart</i></span>
            </li>
            <li class="">
                <a href="<?php echo esc_url(home_url('/orders/')); ?>">
                    <span class="title">Ордера</span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">table</i></span>
            </li>
            <li class="">
                <a href="<?php echo esc_url(home_url('/create-order/')); ?>">
                    <span class="title">Создать ордер</span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">add</i></span>
            </li>
            <?php if ( function_exists('crm_can_access') && crm_can_access('payouts.view') ) : ?>
            <li class="">
                <a href="<?php echo esc_url(home_url('/payouts/')); ?>">
                    <span class="title">Выплаты ЭП</span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">card</i></span>
            </li>
            <?php endif; ?>
            <li class="">
                <a href="<?php echo esc_url(home_url('/users/')); ?>">
                    <span class="title">Пользователи</span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">users</i></span>
            </li>
            <li class="">
                <a href="<?php echo esc_url(home_url('/settings/')); ?>">
                    <span class="title">Настройки</span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">settings</i></span>
            </li>
            <li class="m-b-40">
                <a href="<?php echo esc_url(home_url('/logs/')); ?>">
                    <span class="title">Логи</span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">clipboard</i></span>
            </li>
        </ul>
        <div class="clearfix"></div>
    </div>
    <!-- END SIDEBAR MENU -->
</nav>

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
?>

<nav class="page-sidebar" data-pages="sidebar">
    <!-- BEGIN SIDEBAR MENU TOP TRAY CONTENT-->
    <div class="sidebar-overlay-slide from-top" id="appMenu">
        <div class="row">
            <div class="col-sm-6 no-padding">
                <a href="#" class="p-l-40"><img src="assets/img/demo/social_app.svg" alt="socail">
                </a>
            </div>
            <div class="col-sm-6 no-padding">
                <a href="#" class="p-l-10"><img src="assets/img/demo/email_app.svg" alt="socail">
                </a>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-6 m-t-20 no-padding">
                <a href="#" class="p-l-40"><img src="assets/img/demo/calendar_app.svg" alt="socail">
                </a>
            </div>
            <div class="col-sm-6 m-t-20 no-padding">
                <a href="#" class="p-l-10"><img src="assets/img/demo/add_more.svg" alt="socail">
                </a>
            </div>
        </div>
    </div>
    <!-- END SIDEBAR MENU TOP TRAY CONTENT-->
    <!-- BEGIN SIDEBAR MENU HEADER-->
    <div class="sidebar-header">
        <img src="assets/img/logo_white.png" alt="logo" class="brand" data-src="assets/img/logo_white.png" data-src-retina="assets/img/logo_white_2x.png" width="78" height="22">
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
            <li class="m-t-20 ">
                <a href="index.html" class="detailed">
                    <span class="title">Dashboard</span>
                    <span class="details">12 New Updates</span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">home</i></span>
            </li>
            <li class="">
                <a href="email.html" class="detailed">
                    <span class="title">Email</span>
                    <span class="details">234 New Emails</span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">inbox</i></span>
            </li>
            <li class="">
                <a href="social.html"><span class="title">Social</span></a>
                <span class="icon-thumbnail"><i class="pg-icon">social</i></span>
            </li>
            <li>
                <a href="javascript:;"><span class="title">Calendar</span>
                    <span class=" arrow"></span></a>
                <span class="icon-thumbnail"><i class="pg-icon">calendar</i></span>
                <ul class="sub-menu">
                    <li class="">
                        <a href="calendar.html">Basic</a>
                        <span class="icon-thumbnail"><i class="pg-icon">c</i></span>
                    </li>
                    <li class="">
                        <a href="calendar_lang.html">Languages</a>
                        <span class="icon-thumbnail"><i class="pg-icon">l</i></span>
                    </li>
                    <li class="">
                        <a href="calendar_month.html">Month</a>
                        <span class="icon-thumbnail"><i class="pg-icon">m</i></span>
                    </li>
                    <li class="">
                        <a href="calendar_lazy.html">Lazy load</a>
                        <span class="icon-thumbnail"><i class="pg-icon">la</i></span>
                    </li>
                    <li class="">
                        <a href="https://docs.pages.revox.io/apps/calendar" rel="noreferrer" target="_blank">Documentation</a>
                        <span class="icon-thumbnail"><i class="pg-icon">d</i></span>
                    </li>
                </ul>
            </li>
            <li class="">
                <a href="builder.html">
                    <span class="title">Builder</span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">brush</i></span>
            </li>
            <li class="open active">
                <a href="javascript:;"><span class="title">Layouts</span>
                    <span class=" open  arrow"></span></a>
                <span class="icon-thumbnail"><i class="pg-icon">grid</i></span>
                <ul class="sub-menu">
                    <li class="">
                        <a href="default_layout.html">Default</a>
                        <span class="icon-thumbnail"><i class="pg-icon">dl</i></span>
                    </li>
                    <li class="">
                        <a href="secondary_layout.html">Secondary</a>
                        <span class="icon-thumbnail"><i class="pg-icon">sl</i></span>
                    </li>
                    <li class="">
                        <a href="boxed_layout.html">Boxed</a>
                        <span class="icon-thumbnail"><i class="pg-icon">bl</i></span>
                    </li>
                </ul>
            </li>
            <li>
                <a href="javascript:;"><span class="title">UI Elements</span>
                    <span class=" arrow"></span></a>
                <span class="icon-thumbnail"><i class="pg-icon">shapes</i></span>
                <ul class="sub-menu">
                    <li class="">
                        <a href="color.html">Color</a>
                        <span class="icon-thumbnail"><i class="pg-icon">c</i></span>
                    </li>
                    <li class="">
                        <a href="typography.html">Typography</a>
                        <span class="icon-thumbnail"><i class="pg-icon">t</i></span>
                    </li>
                    <li class="">
                        <a href="icons.html">Icons</a>
                        <span class="icon-thumbnail"><i class="pg-icon">i</i></span>
                    </li>
                    <li class="">
                        <a href="buttons.html">Buttons</a>
                        <span class="icon-thumbnail"><i class="pg-icon">b</i></span>
                    </li>
                    <li class="">
                        <a href="notifications.html">Notifications</a>
                        <span class="icon-thumbnail"><i class="pg-icon">n</i></span>
                    </li>
                    <li class="">
                        <a href="modals.html">Modals</a>
                        <span class="icon-thumbnail"><i class="pg-icon">m</i></span>
                    </li>
                    <li class="">
                        <a href="progress.html">Progress &amp; Activity</a>
                        <span class="icon-thumbnail"><i class="pg-icon">pa</i></span>
                    </li>
                    <li class="">
                        <a href="tabs_accordian.html">Tabs &amp; Accordions</a>
                        <span class="icon-thumbnail"><i class="pg-icon">ta</i></span>
                    </li>
                    <li class="">
                        <a href="sliders.html">Sliders</a>
                        <span class="icon-thumbnail"><i class="pg-icon">s</i></span>
                    </li>
                    <li class="">
                        <a href="tree_view.html">Tree View</a>
                        <span class="icon-thumbnail"><i class="pg-icon">tv</i></span>
                    </li>
                    <li class="">
                        <a href="nestables.html">Nestable</a>
                        <span class="icon-thumbnail"><i class="pg-icon">ns</i></span>
                    </li>
                </ul>
            </li>
            <li>
                <a href="javascript:;">
                    <span class="title">Forms</span>
                    <span class=" arrow"></span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">note</i></span>
                <ul class="sub-menu">
                    <li class="">
                        <a href="form_elements.html">Form Elements</a>
                        <span class="icon-thumbnail"><i class="pg-icon">fe</i></span>
                    </li>
                    <li class="">
                        <a href="form_layouts.html">Form Layouts</a>
                        <span class="icon-thumbnail"><i class="pg-icon">fl</i></span>
                    </li>
                    <li class="">
                        <a href="form_wizard.html">Form Wizard</a>
                        <span class="icon-thumbnail"><i class="pg-icon">fw</i></span>
                    </li>
                </ul>
            </li>
            <li class="">
                <a href="cards.html">
                    <span class="title">Cards</span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">card</i></span>
            </li>
            <li class="">
                <a href="views.html">
                    <span class="title">Views</span>
                </a>
                <span class="icon-thumbnail"><i class="pg-icon">layout</i></span>
            </li>
            <li>
                <a href="javascript:;"><span class="title">Tables</span>
                    <span class=" arrow"></span></a>
                <span class="icon-thumbnail"><i class="pg-icon">table</i></span>
                <ul class="sub-menu">
                    <li class="">
                        <a href="tables.html">Basic Tables</a>
                        <span class="icon-thumbnail"><i class="pg-icon">bt</i></span>
                    </li>
                    <li class="">
                        <a href="datatables.html">Data Tables</a>
                        <span class="icon-thumbnail"><i class="pg-icon">dt</i></span>
                    </li>
                </ul>
            </li>
            <li>
                <a href="javascript:;"><span class="title">Maps</span>
                    <span class=" arrow"></span></a>
                <span class="icon-thumbnail"><i class="pg-icon">map</i></span>
                <ul class="sub-menu">
                    <li class="">
                        <a href="google_map.html">Google Maps</a>
                        <span class="icon-thumbnail"><i class="pg-icon">gm</i></span>
                    </li>
                    <li class="">
                        <a href="vector_map.html">Vector Maps</a>
                        <span class="icon-thumbnail"><i class="pg-icon">vm</i></span>
                    </li>
                </ul>
            </li>
            <li class="">
                <a href="charts.html"><span class="title">Charts</span></a>
                <span class="icon-thumbnail"><i class="pg-icon">chart</i></span>
            </li>
            <li>
                <a href="javascript:;"><span class="title">Extra</span>
                    <span class=" arrow"></span></a>
                <span class="icon-thumbnail"><i class="pg-icon">effects</i></span>
                <ul class="sub-menu">
                    <li class="">
                        <a href="invoice.html">Invoice</a>
                        <span class="icon-thumbnail"><i class="pg-icon">in</i></span>
                    </li>
                    <li class="">
                        <a href="404.html">404 Page</a>
                        <span class="icon-thumbnail"><i class="pg-icon">pg</i></span>
                    </li>
                    <li class="">
                        <a href="500.html">500 Page</a>
                        <span class="icon-thumbnail"><i class="pg-icon">pg</i></span>
                    </li>
                    <li class="">
                        <a href="blank_template.html">Blank Page</a>
                        <span class="icon-thumbnail"><i class="pg-icon">bp</i></span>
                    </li>
                    <li class="">
                        <a href="login.html">Login</a>
                        <span class="icon-thumbnail"><i class="pg-icon">li</i></span>
                    </li>
                    <li class="">
                        <a href="register.html">Register</a>
                        <span class="icon-thumbnail"><i class="pg-icon">re</i></span>
                    </li>
                    <li class="">
                        <a href="lock_screen.html">Lockscreen</a>
                        <span class="icon-thumbnail"><i class="pg-icon">ls</i></span>
                    </li>
                    <li class="">
                        <a href="gallery.html">Gallery</a>
                        <span class="icon-thumbnail"><i class="pg-icon">gl</i></span>
                    </li>
                    <li class="">
                        <a href="timeline.html">Timeline</a>
                        <span class="icon-thumbnail"><i class="pg-icon">tl</i></span>
                    </li>
                </ul>
            </li>
            <li class="">
                <a href="javascript:;"><span class="title">Menu Levels</span>
                    <span class="arrow"></span></a>
                <span class="icon-thumbnail"><i class="pg-icon">menu_level</i></span>
                <ul class="sub-menu">
                    <li>
                        <a href="javascript:;">Level 1</a>
                        <span class="icon-thumbnail"><i class="pg-icon">l1</i></span>
                    </li>
                    <li>
                        <a href="javascript:;"><span class="title">Level 2</span>
                            <span class="arrow"></span></a>
                        <span class="icon-thumbnail"><i class="pg-icon">L2</i></span>
                        <ul class="sub-menu">
                            <li>
                                <a href="javascript:;">Sub Menu</a>
                                <span class="icon-thumbnail"><i class="pg-icon">sm</i></span>
                            </li>
                            <li>
                                <a href="ujavascript:;">Sub Menu</a>
                                <span class="icon-thumbnail"><i class="pg-icon">sm</i></span>
                            </li>
                        </ul>
                    </li>
                </ul>
            </li>
            <li class="">
                <a href="https://docs.pages.revox.io/" rel="noreferrer" target="_blank"><span class="title">Docs</span></a>
                <span class="icon-thumbnail"><i class="pg-icon">flag</i></span>
            </li>
            <li class="m-b-40">
                <a href="http://changelog.pages.revox.io/" rel="noreferrer" target="_blank"><span class="title">Changelog</span></a>
                <span class="icon-thumbnail"><i class="pg-icon">clipboard</i></span>
            </li>
        </ul>
        <div class="clearfix"></div>
    </div>
    <!-- END SIDEBAR MENU -->
</nav>
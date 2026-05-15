<?php
/*
Template Name: Login Page
Slug: authorization
*/

if (!defined('ABSPATH')) exit;

if (is_user_logged_in()) {
    wp_safe_redirect(malibu_exchange_get_dashboard_url());
    exit;
}

$state = malibu_exchange_handle_login_submission();

get_header();
?>

<div class="login-wrapper">
    <!-- START Login Background Pic Wrapper-->
    <div class="bg-pic">
        <div class="bg-caption pull-bottom sm-pull-bottom text-white p-l-20 m-b-20">
            <h1 class="semi-bold text-white">Malibu Exchange</h1>
        </div>
    </div>
    <!-- END Login Background Pic Wrapper-->

    <!-- START Login Right Container-->
    <div class="login-container bg-white">
        <div class="p-l-50 p-r-50 p-t-50 m-t-30 sm-p-l-15 sm-p-r-15 sm-p-t-40">
            <img src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/img/malibu-exchange-sidebar-logo.png"
                 alt="Malibu Exchange"
                 width="48" height="27">

            <h2 class="p-t-25">Get Started <br> with your Dashboard</h2>
            <p class="mw-80 m-t-5">Sign in to your account</p>

            <?php if (!empty($state['errors'])): ?>
                <div class="alert alert-danger m-t-10">
                    <?php foreach ($state['errors'] as $err): ?>
                        <p class="m-b-0"><?php echo esc_html($err); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($state['notice'])): ?>
                <div class="alert alert-info m-t-10">
                    <p class="m-b-0"><?php echo esc_html($state['notice']); ?></p>
                </div>
            <?php endif; ?>

            <!-- START Login Form -->
            <form id="form-login" class="p-t-15" role="form" method="post" action="">
                <?php wp_nonce_field('malibu_exchange_login'); ?>
                <input type="hidden" name="me_login_action" value="1">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($state['redirect_to']); ?>">

                <!-- Honeypot -->
                <div style="display:none" aria-hidden="true">
                    <input type="text" name="website" value="" autocomplete="off" tabindex="-1">
                </div>

                <!-- START Form Control-->
                <div class="form-group form-group-default">
                    <label>Login</label>
                    <div class="controls">
                        <input type="text"
                               name="log"
                               placeholder="User Name"
                               class="form-control"
                               value="<?php echo esc_attr($state['username']); ?>"
                               autocomplete="username"
                               required>
                    </div>
                </div>
                <!-- END Form Control-->

                <!-- START Form Control-->
                <div class="form-group form-group-default">
                    <label>Password</label>
                    <div class="controls">
                        <input type="password"
                               class="form-control"
                               name="pwd"
                               placeholder="Credentials"
                               autocomplete="current-password"
                               required>
                    </div>
                </div>
                <!-- END Form Control-->

                <div class="row">
                    <div class="col-md-6 no-padding sm-p-l-10">
                        <div class="form-check">
                            <input type="checkbox"
                                   value="forever"
                                   id="rememberme"
                                   name="rememberme"
                                   <?php checked($state['remember']); ?>>
                            <label for="rememberme">Remember me</label>
                        </div>
                    </div>
                    <div class="col-md-6 d-flex align-items-center justify-content-end">
                        <button class="btn btn-primary btn-lg m-t-10" type="submit">Sign in</button>
                    </div>
                </div>

                <div class="m-b-5 m-t-30">
                    <a href="<?php echo esc_url(wp_lostpassword_url()); ?>" class="normal">Lost your password?</a>
                </div>
            </form>
            <!--END Login Form-->

            <div class="pull-bottom sm-pull-bottom">
                <div class="m-b-30 p-r-80 sm-m-t-20 sm-p-r-15 sm-p-b-20 clearfix">
                    <div class="col-sm-9 no-padding m-t-10">
                        <p class="small-text normal hint-text">
                            <?php get_template_part( 'template-parts/footer-copyright-text' ); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- END Login Right Container-->
</div>

<?php get_footer(); ?>

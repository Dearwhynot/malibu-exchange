<?php
/*
Template Name: Login
*/
if (is_user_logged_in()) {
    wp_safe_redirect(home_url('/dashboard'));
    exit;
}
get_header();
?>
<main class="me-login">
    <div class="me-login__card">
        <div class="me-login__eyebrow">Malibu Exchange</div>
        <h1>Welcome back</h1>
        <p>Sign in to manage bot orders, rates and settings.</p>
        <?php wp_login_form([
            'label_username' => 'Login',
            'label_password' => 'Password',
            'label_log_in'   => 'Enter backoffice',
            'remember'       => true,
            'redirect'       => home_url('/dashboard'),
        ]); ?>
    </div>
</main>
<?php get_footer(); ?>

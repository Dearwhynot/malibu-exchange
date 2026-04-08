<?php
/*
Template Name: Login
Slug: authorization
*/
if (is_user_logged_in()) {
    wp_safe_redirect(malibu_exchange_get_dashboard_url());
    exit;
}

$login_state = malibu_exchange_handle_login_submission();
$logo_uri = malibu_exchange_theme_asset_uri('theme source html bootstrap demo/condensed/assets/img/logo-48x48_c.png');
$logo_retina_uri = malibu_exchange_theme_asset_uri('theme source html bootstrap demo/condensed/assets/img/logo-48x48_c@2x.png');

get_header();
?>
<main class="login-wrapper">
    <div class="bg-pic">
        <div class="bg-caption pull-bottom sm-pull-bottom text-white p-l-20 m-b-20">
            <h1 class="semi-bold text-white">Операторский вход в обменный backoffice</h1>
            <p class="small">
                Заходите в защищённую рабочую зону для заявок, курсов, настроек и сервисных операций по организациям.
            </p>
        </div>
    </div>

    <div class="login-container bg-white">
        <div class="p-l-50 p-r-50 p-t-50 m-t-30 sm-p-l-15 sm-p-r-15 sm-p-t-40">
            <img src="<?php echo esc_url($logo_uri); ?>" alt="logo" data-src="<?php echo esc_url($logo_uri); ?>" data-src-retina="<?php echo esc_url($logo_retina_uri); ?>" width="48" height="48">
            <h2 class="p-t-25">Авторизация <br/>оператора</h2>
            <p class="mw-80 m-t-5">Войдите, чтобы управлять курсами, заявками и настройками.</p>

            <?php if ($login_state['notice'] !== '') : ?>
                <div class="alert alert-info m-t-20" role="alert">ы
                    <?php echo esc_html($login_state['notice']); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($login_state['errors'])) : ?>
                <div class="alert alert-danger m-t-20" role="alert">
                    <?php foreach ($login_state['errors'] as $error_message) : ?>
                        <div><?php echo esc_html($error_message); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form id="malibu-login-form" class="p-t-15" role="form" method="post" action="<?php echo esc_url(malibu_exchange_get_login_url()); ?>">
                <?php wp_nonce_field('malibu_exchange_login'); ?>
                <input type="hidden" name="me_login_action" value="1">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($login_state['redirect_to']); ?>">

                <div aria-hidden="true" style="position:absolute;left:-9999px;opacity:0;pointer-events:none;">
                    <label for="malibu_login_website">Website</label>
                    <input type="text" id="malibu_login_website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="form-group form-group-default<?php echo $login_state['username'] !== '' ? ' focused' : ''; ?>">
                    <label for="malibu_login_user">Логин</label>
                    <div class="controls">
                        <input
                            type="text"
                            id="malibu_login_user"
                            name="log"
                            class="form-control"
                            placeholder="Введите логин"
                            value="<?php echo esc_attr($login_state['username']); ?>"
                            autocomplete="username"
                            required
                        >
                    </div>
                </div>

                <div class="form-group form-group-default">
                    <label for="malibu_login_password">Пароль</label>
                    <div class="controls">
                        <input
                            type="password"
                            id="malibu_login_password"
                            name="pwd"
                            class="form-control"
                            placeholder="Введите пароль"
                            autocomplete="current-password"
                            required
                        >
                    </div>
                </div>

                <div class="row m-t-20">
                    <div class="col-md-6 no-padding sm-p-l-10">
                        <div class="form-check">
                            <input type="checkbox" value="forever" id="rememberme" name="rememberme" <?php checked($login_state['remember']); ?>>
                            <label for="rememberme">Запомнить меня</label>
                        </div>
                    </div>
                    <div class="col-md-6 d-flex align-items-center justify-content-end">
                        <button class="btn btn-primary btn-lg m-t-10 malibu-login__submit" type="submit">Войти</button>
                    </div>
                </div>

                <div class="m-b-5 m-t-30">
                    <a href="#" class="normal">Забыли пароль?</a>
                </div>
                <div class="m-t-10">
                    <a href="#" class="normal">Ещё не участник? Зарегистрируйтесь.</a>
                </div>
            </form>

            <div class="pull-bottom sm-pull-bottom">
                <div class="m-b-30 p-r-80 sm-m-t-20 sm-p-r-15 sm-p-b-20 clearfix">
                    <div class="col-sm-9 no-padding m-t-10">
                        <p class="small-text normal hint-text">
                            ©2026 Malibu Exchange. Заглушки для <a href="#">Cookie Policy</a>, <a href="#">Privacy and Terms</a>.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<?php get_footer(); ?>

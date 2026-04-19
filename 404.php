<?php
/**
 * 404 Not Found — страница ошибки.
 *
 * WordPress автоматически использует этот файл при отсутствии запрошенного контента.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

status_header( 404 );

$theme_uri = get_template_directory_uri();
$home_url  = home_url( '/' );
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="content-type" content="text/html;charset=UTF-8" />
    <meta charset="utf-8" />
    <title>404 — Страница не найдена | Malibu Exchange</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no" />
    <link href="<?php echo esc_url( $theme_uri . '/vendor/pages/assets/plugins/bootstrap/css/bootstrap.min.css' ); ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo esc_url( $theme_uri . '/vendor/pages/pages/css/pages.css' ); ?>" rel="stylesheet" type="text/css" />
</head>
<body class="fixed-header error-page">

    <!-- BEGIN ERROR CONTAINER -->
    <div class="d-flex justify-content-center full-height full-width align-items-center">
        <div class="error-container text-center">
            <h1 class="error-number">404</h1>
            <h2 class="semi-bold">Страница не найдена</h2>
            <p class="p-b-10">Запрашиваемая страница не существует или была перемещена.</p>
            <div class="m-t-20">
                <a href="<?php echo esc_url( $home_url ); ?>" class="btn btn-primary btn-cons">
                    На главную
                </a>
            </div>
        </div>
    </div>
    <!-- END ERROR CONTAINER -->

    <!-- BEGIN BOTTOM BAR -->
    <div class="pull-bottom sm-pull-bottom full-width d-flex align-items-center justify-content-center">
        <div class="error-container">
            <div class="error-container-innner">
                <div class="p-b-30 sm-p-b-20 d-flex align-items-center justify-content-center">
                    <p class="small no-margin hint-text">
                        Malibu Exchange &mdash; внутренняя панель оператора
                    </p>
                </div>
            </div>
        </div>
    </div>
    <!-- END BOTTOM BAR -->

    <script src="<?php echo esc_url( $theme_uri . '/vendor/pages/assets/plugins/jquery/jquery-3.2.1.min.js' ); ?>" type="text/javascript"></script>
    <script src="<?php echo esc_url( $theme_uri . '/vendor/pages/pages/js/pages.js' ); ?>" type="text/javascript"></script>
</body>
</html>

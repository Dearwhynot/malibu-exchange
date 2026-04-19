<?php
/**
 * 503 Service Unavailable — страница ошибки технического обслуживания.
 *
 * Использование:
 *  - Подключить через веб-сервер (Apache: ErrorDocument 503 /wp-content/themes/<theme>/503.php)
 *  - Или задействовать через WordPress при плановых работах (вызвать напрямую + exit)
 *
 * Файл намеренно не зависит от WordPress — работает и без него.
 */

// Если WordPress загружен — используем его; если нет — обходимся без него.
$theme_uri = '';
$home_url  = '/';

if ( defined( 'ABSPATH' ) ) {
    status_header( 503 );
    header( 'Retry-After: 3600' );
    $theme_uri = get_template_directory_uri();
    $home_url  = home_url( '/' );
} else {
    http_response_code( 503 );
    header( 'Retry-After: 3600' );

    // Пытаемся вычислить путь к ассетам относительно сервера
    $proto     = ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $theme_uri = $proto . '://' . $host . '/wp-content/themes/malibu-exchange';
    $home_url  = $proto . '://' . $host . '/';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="content-type" content="text/html;charset=UTF-8" />
    <meta charset="utf-8" />
    <title>503 — Технические работы | Malibu Exchange</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no" />
    <link href="<?php echo htmlspecialchars( $theme_uri . '/vendor/pages/assets/plugins/bootstrap/css/bootstrap.min.css', ENT_QUOTES, 'UTF-8' ); ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo htmlspecialchars( $theme_uri . '/vendor/pages/pages/css/pages.css', ENT_QUOTES, 'UTF-8' ); ?>" rel="stylesheet" type="text/css" />
</head>
<body class="fixed-header error-page">

    <!-- BEGIN ERROR CONTAINER -->
    <div class="d-flex justify-content-center full-height full-width align-items-center">
        <div class="error-container text-center">
            <h1 class="error-number">503</h1>
            <h2 class="semi-bold">Технические работы</h2>
            <p class="p-b-10">Сервис временно недоступен. Пожалуйста, попробуйте позже.</p>
            <div class="m-t-20">
                <a href="<?php echo htmlspecialchars( $home_url, ENT_QUOTES, 'UTF-8' ); ?>" class="btn btn-primary btn-cons">
                    Попробовать снова
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

    <script src="<?php echo htmlspecialchars( $theme_uri . '/vendor/pages/assets/plugins/jquery/jquery-3.2.1.min.js', ENT_QUOTES, 'UTF-8' ); ?>" type="text/javascript"></script>
    <script src="<?php echo htmlspecialchars( $theme_uri . '/vendor/pages/pages/js/pages.js', ENT_QUOTES, 'UTF-8' ); ?>" type="text/javascript"></script>
</body>
</html>

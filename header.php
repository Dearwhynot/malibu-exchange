<?php

/**
 * Общий header темы.
 */

if (!defined('ABSPATH')) {
    exit;
}

$theme_uri = get_template_directory_uri();
$pages_css_uri = $theme_uri . '/vendor/pages/pages/css/pages.css';
$pages_ico_base_uri = $theme_uri . '/vendor/pages/pages/ico';

$site_name = trim( wp_strip_all_tags( get_bloginfo( 'name', 'raw' ) ) );
if ( $site_name === '' ) {
    $site_name = 'Malibu Exchange';
}

$page_title = '';
if ( is_singular() ) {
    $page_title = single_post_title( '', false );
    if ( $page_title === '' ) {
        $post_id = get_queried_object_id();
        $page_title = $post_id ? get_the_title( $post_id ) : '';
    }
} elseif ( is_404() ) {
    $page_title = 'Страница не найдена';
} elseif ( is_search() ) {
    $page_title = 'Поиск';
} elseif ( is_archive() ) {
    $page_title = get_the_archive_title();
}

$page_title = trim( wp_strip_all_tags( (string) $page_title ) );
$document_title = ( $page_title !== '' && strcasecmp( $page_title, $site_name ) !== 0 )
    ? $site_name . ' - ' . $page_title
    : $site_name;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="content-type" content="text/html;charset=UTF-8" />
    <meta charset="utf-8" />
    <title><?php echo esc_html( $document_title ); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no" />
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-touch-fullscreen" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta content="Meet pages - The simplest and fastest way to build web UI for your dashboard or app." name="description" />
    <meta content="Ace" name="author" />
    <!-- Please remove the file below for production: Contains demo classes -->
    <?php wp_head(); ?>
</head>

<body class="fixed-header ">

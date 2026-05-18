<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'crm_product_system_org_id' ) ) {
	function crm_product_system_org_id(): int {
		return 0;
	}
}

if ( ! function_exists( 'crm_get_product_release_version' ) ) {
	function crm_get_product_release_version(): string {
		$version = trim( (string) crm_get_setting( 'product_release_version', crm_product_system_org_id(), '' ) );

		if ( $version !== '' ) {
			return $version;
		}

		return '0.1.0';
	}
}

if ( ! function_exists( 'crm_get_product_page_by_slug' ) ) {
	function crm_get_product_page_by_slug( string $slug ): ?WP_Post {
		$page = get_page_by_path( $slug, OBJECT, 'page' );

		return $page instanceof WP_Post ? $page : null;
	}
}

if ( ! function_exists( 'crm_get_product_roadmap_page' ) ) {
	function crm_get_product_roadmap_page(): ?WP_Post {
		return crm_get_product_page_by_slug( 'roadmap' );
	}
}

if ( ! function_exists( 'crm_get_product_release_notes_page' ) ) {
	function crm_get_product_release_notes_page(): ?WP_Post {
		return crm_get_product_page_by_slug( 'release-notes' );
	}
}

if ( ! function_exists( 'crm_get_product_roadmap_url' ) ) {
	function crm_get_product_roadmap_url(): string {
		$page = crm_get_product_roadmap_page();

		return $page ? (string) get_permalink( $page ) : home_url( '/roadmap/' );
	}
}

if ( ! function_exists( 'crm_get_product_release_notes_url' ) ) {
	function crm_get_product_release_notes_url(): string {
		$page = crm_get_product_release_notes_page();

		return $page ? (string) get_permalink( $page ) : home_url( '/release-notes/' );
	}
}

if ( ! function_exists( 'crm_get_product_page_edit_url' ) ) {
	function crm_get_product_page_edit_url( ?WP_Post $page ): string {
		if ( ! $page instanceof WP_Post ) {
			return '';
		}

		$edit_url = get_edit_post_link( $page->ID, 'raw' );

		return is_string( $edit_url ) ? $edit_url : '';
	}
}

if ( ! function_exists( 'crm_product_content_has_visual_media' ) ) {
	function crm_product_content_has_visual_media( string $content ): bool {
		$haystack = strtolower( $content );

		return strpos( $haystack, '<img' ) !== false
			|| strpos( $haystack, '[gallery' ) !== false
			|| strpos( $haystack, '[caption' ) !== false
			|| strpos( $haystack, 'wp:image' ) !== false
			|| strpos( $haystack, 'wp:gallery' ) !== false;
	}
}

if ( ! function_exists( 'crm_product_get_page_screenshot_attachments' ) ) {
	function crm_product_get_page_screenshot_attachments( int $post_id ): array {
		$attachments = get_attached_media( 'image', $post_id );

		if ( ! is_array( $attachments ) || empty( $attachments ) ) {
			return [];
		}

		return array_values( array_filter(
			$attachments,
			static fn( $attachment ) => $attachment instanceof WP_Post
		) );
	}
}

if ( ! function_exists( 'crm_product_get_modified_label' ) ) {
	function crm_product_get_modified_label( int $post_id ): string {
		$modified_gmt = get_post_field( 'post_modified_gmt', $post_id );
		if ( ! is_string( $modified_gmt ) || $modified_gmt === '' || $modified_gmt === '0000-00-00 00:00:00' ) {
			return '';
		}

		try {
			$dt = new DateTime( $modified_gmt, new DateTimeZone( 'UTC' ) );
			$dt->setTimezone( wp_timezone() );

			return $dt->format( 'd.m.Y H:i' );
		} catch ( Exception $e ) {
			return '';
		}
	}
}

if ( ! function_exists( 'crm_product_sanitize_release_version' ) ) {
	function crm_product_sanitize_release_version( string $raw_version ): string {
		$version = sanitize_text_field( $raw_version );
		$version = preg_replace( '/[^0-9A-Za-z.\-_+ ]/', '', $version );
		$version = is_string( $version ) ? trim( preg_replace( '/\s+/', ' ', $version ) ) : '';

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $version, 0, 64 );
		}

		return substr( $version, 0, 64 );
	}
}

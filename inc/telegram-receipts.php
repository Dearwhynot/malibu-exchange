<?php
/**
 * Malibu Exchange — Telegram Receipt Formatting
 *
 * Единый formatter для строгих моноширинных receipt-блоков в Telegram.
 * Основа — HTML <pre>, чтобы Telegram сохранял пробелы и вертикальное выравнивание.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'crm_tg_receipt_escape' ) ) {
	function crm_tg_receipt_escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'crm_tg_receipt_pad' ) ) {
	function crm_tg_receipt_pad( string $value, int $width ): string {
		$length = mb_strlen( $value );
		if ( $length >= $width ) {
			return $value;
		}

		return $value . str_repeat( ' ', $width - $length );
	}
}

if ( ! function_exists( 'crm_tg_receipt_format_number' ) ) {
	function crm_tg_receipt_format_number( float $value, int $decimals = 2, bool $trim_trailing_zeros = true ): string {
		$formatted = number_format( $value, max( 0, $decimals ), '.', ',' );

		if ( ! $trim_trailing_zeros || strpos( $formatted, '.' ) === false ) {
			return $formatted;
		}

		return rtrim( rtrim( $formatted, '0' ), '.' );
	}
}

if ( ! function_exists( 'crm_tg_receipt_format_amount' ) ) {
	function crm_tg_receipt_format_amount( float $value, string $currency, int $decimals = 2, bool $trim_trailing_zeros = true ): string {
		return crm_tg_receipt_format_number( $value, $decimals, $trim_trailing_zeros ) . ' ' . strtoupper( trim( $currency ) );
	}
}

if ( ! function_exists( 'crm_tg_receipt_row' ) ) {
	function crm_tg_receipt_row( string $label, string $value, int $label_width = 10 ): string {
		return crm_tg_receipt_pad( $label, $label_width ) . $value;
	}
}

if ( ! function_exists( 'crm_tg_receipt_block' ) ) {
	function crm_tg_receipt_block(
		array $main_rows,
		array $meta_rows = [],
		array $footer_lines = [],
		string $title = 'EXCHANGE RECEIPT'
	): string {
		$separator = '━━━━━━━━━━━━━━━━━━━';
		$lines     = [
			trim( $title ) !== '' ? $title : 'EXCHANGE RECEIPT',
			'',
			$separator,
		];

		foreach ( $main_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$label = isset( $row['label'] ) ? (string) $row['label'] : '';
			$value = isset( $row['value'] ) ? (string) $row['value'] : '';
			$lines[] = crm_tg_receipt_row( $label, $value );
		}

		$lines[] = $separator;

		if ( ! empty( $meta_rows ) ) {
			$lines[] = '';
			foreach ( $meta_rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$label = isset( $row['label'] ) ? (string) $row['label'] : '';
				$value = isset( $row['value'] ) ? (string) $row['value'] : '';
				$lines[] = crm_tg_receipt_row( $label, $value );
			}
		}

		if ( ! empty( $footer_lines ) ) {
			$lines[] = '';
			$lines[] = $separator;
			$lines[] = '';
			foreach ( $footer_lines as $line ) {
				$lines[] = (string) $line;
			}
		}

		return '<pre>' . crm_tg_receipt_escape( implode( "\n", $lines ) ) . '</pre>';
	}
}

<?php
defined( 'ABSPATH' ) || exit;
$mv_create_enable_print_thumbnails = \Mediavine\Settings::get_setting( 'mv_create_enable_print_thumbnails' );
if ( ! $args['print'] || ! empty( $mv_create_enable_print_thumbnails ) ) {
	// Editorial theme uses no fixed ratio for full-width images
	$img_size = 'mv_create_no_ratio';

	// Check for setting override
	$img_size_setting = \Mediavine\Settings::get_setting( 'mv_create_photo_ratio' );
	if ( ! empty( $img_size_setting ) ) {
		$img_size = $img_size_setting;
	}

	if ( isset( $args['creation']['images'][ $img_size ] ) ) {
		echo wp_kses_post( $args['creation']['images'][ $img_size ] );
	} elseif ( isset( $args['creation']['images']['mv_create_16x9'] ) ) {
		// Fallback to 16x9 if no_ratio not available
		echo wp_kses_post( $args['creation']['images']['mv_create_16x9'] );
	}
}

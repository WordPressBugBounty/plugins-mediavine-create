<?php
// Default the author to the text stored in the global settings. Then check for the existence of a
// custom field overriding the affiliate message. If it exists, use the custom field for this card.
$copyright = null;
if ( ! empty( $args['creation']['create_settings'] ) ) {
	$copyright = $args['creation']['create_settings'][ \Mediavine\Create\Plugin::$settings_group . '_copyright_attribution' ];
	if ( ! empty( $args['creation']['author'] ) ) {
		$copyright = $args['creation']['author'];
	}
}
?>

<div class="mv-create-footer-flexbox">

	<?php if ( $copyright ) { ?>
		<?php
		/**
		 * Filter the copyright/author HTML output in Create cards
		 *
		 * This filter allows developers to customize the copyright/author section,
		 * such as converting it to a link to the author's page.
		 *
		 * @param string $copyright_html The HTML for the copyright section
		 * @param string $copyright      The copyright/author text
		 * @param array  $args           The card arguments including full creation data
		 *
		 * @since 1.10.2
		 */
		$copyright_html = apply_filters(
			'mv_create_copyright_html',
			'<div class="mv-create-copy">&copy; ' . wp_kses_post( $copyright ) . '</div>',
			$copyright,
			$args
		);
		echo wp_kses_post( $copyright_html );
		?>
	<?php } ?>

	<div class="mv-create-categories">

		<?php
		if ( ! empty( $args['creation']['secondary_term_name'] ) ) {
		?>
			<span class="mv-create-cuisine">
				<strong class="mv-create-uppercase mv-create-strong">
					<?php echo esc_html( $args['creation']['secondary_term_label'] ); ?>:
				</strong>
				<?php echo esc_html( $args['creation']['secondary_term_name'] ); ?>
			</span>
			<?php if ( ! empty( $args['creation']['category_name'] ) ) { ?>
				<span class="mv-create-spacer">/</span>
			<?php } ?>
		<?php } ?>

		<?php if ( ! empty( $args['creation']['category_name'] ) ) { ?>
			<span class="mv-create-category"><strong class="mv-create-uppercase mv-create-strong"><?php esc_html_e( 'Category', 'mediavine' ); ?>:</strong> <?php echo esc_html( $args['creation']['category_name'] ); ?></span>
		<?php } ?>

	</div>

	<?php
	if ( isset( $args['creation']['images']['mv_create_vert'] ) ) {
		echo wp_kses_post( $args['creation']['images']['mv_create_vert'] );
	}
	?>

</div>

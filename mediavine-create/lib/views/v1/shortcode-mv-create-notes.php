<?php if ( ! empty( $args['creation']['notes'] ) ) {
	/* @since 1.9.0 mv-create-notes-slot-v2 is a potential target for the MV Web Wrapper. */
	?>
	<div class="mv-create-notes mv-create-notes-slot-v2">
		<h2 class="mv-create-notes-title mv-create-title-secondary"><?php esc_html_e( 'Notes', 'mediavine' ); ?></h2>
		<div class="mv-create-notes-content">
			<p><?php echo wp_kses_post( do_shortcode( $args['creation']['notes'] ) ); ?></p>
		</div>
	</div>
<?php
}

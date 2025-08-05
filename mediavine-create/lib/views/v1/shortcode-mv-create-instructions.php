<?php 
/**
 * Instructions are now cleaned during publishing to remove empty Slate editor artifacts
 * and have schema IDs properly injected. Minimal processing needed here.
 */
if ( ! empty( $args['creation']['instructions'] ) ) {
	$instructions = $args['creation']['instructions'];
	
	// Only minimal cleanup needed for legacy content compatibility
	$sanitized = str_replace( '<p><br></p>', '', $instructions );
?>
	<?php if ( empty( $args['print'] ) ) { ?>
	<div class="mv-create-hands-free"></div>
	<?php
	}
	/* @since 1.9.0 mv-create-instructions-slot-v2 is targetted by the MV Web Wrapper. */
	?>
	<div class="mv-create-instructions mv-create-instructions-slot-v2">
		<h2 class="mv-create-instructions-title mv-create-title-secondary"><?php esc_html_e( 'Instructions', 'mediavine' ); ?></h2>
		<?php echo wp_kses_post( do_shortcode( $sanitized ) ); ?>
	</div>
<?php
}

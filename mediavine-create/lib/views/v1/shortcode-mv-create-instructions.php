<?php
defined( 'ABSPATH' ) || exit;
/**
 * Instructions are now cleaned during publishing to remove empty Slate editor artifacts
 * and have schema IDs properly injected. Minimal processing needed here.
 */
if ( ! empty( $args['creation']['instructions'] ) ) {
	$instructions = $args['creation']['instructions'];

	// Only minimal cleanup needed for legacy content compatibility
	$sanitized = str_replace( '<p><br></p>', '', $instructions );

	// Add data-step-index attributes to list items for checklist feature
	// This matches the schema IDs already present (id="mv_create_{id}_{index}")
	$step_index = 0;
	$sanitized = preg_replace_callback(
		'/<li([^>]*)>/i',
		function( $matches ) use ( &$step_index ) {
			$attrs = $matches[1];
			// Add data-step-index attribute
			$result = '<li data-step-index="' . $step_index . '"' . $attrs . '>';
			$step_index++;
			return $result;
		},
		$sanitized
	);
?>
	<?php
	// Interactive mode may be on too; the settings screen warns rather than forcing
	// either off. The client only mounts the toggle when hands-free is enabled.
	if ( empty( $args['print'] ) ) {
		?>
	<div class="mv-create-hands-free"></div>
	<?php
	}
	/* @since 1.9.0 mv-create-instructions-slot-v2 is targetted by the MV Web Wrapper. */
	?>
	<div class="mv-create-instructions mv-create-instructions-slot-v2">
		<h2 class="mv-create-instructions-title mv-create-title-secondary"><?php esc_html_e( 'Instructions', 'mediavine-create' ); ?></h2>
		<?php echo wp_kses_post( do_shortcode( \Mediavine\Create\Plugin::unfurl_media_urls( $sanitized ) ) ); ?>
	</div>
<?php
}

<?php defined( 'ABSPATH' ) || exit; ?>
<?php if ( ! empty( $args['creation']['notes'] ) ) {
	/* @since 1.9.0 mv-create-notes-slot-v2 is a potential target for the MV Web Wrapper. */
	?>
	<div class="mv-create-notes mv-create-notes-slot-v2">
		<h2 class="mv-create-notes-title mv-create-title-secondary"><?php esc_html_e( 'Notes', 'mediavine-create' ); ?></h2>
		<div class="mv-create-notes-content">
			<p><?php echo \Mediavine\Create\Creations_Views::render_author_html( \Mediavine\Create\Shortcodes::unfurl_media_urls( $args['creation']['notes'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized by render_author_html(). ?></p>
		</div>
	</div>
<?php
}

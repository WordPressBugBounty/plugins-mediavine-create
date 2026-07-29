<?php defined( 'ABSPATH' ) || exit; ?>
<?php if ( ! $args['print'] && $args['allow_reviews'] ) { ?>
	<div id="mv-create-<?php echo esc_attr( $args['creation']['id'] ); ?>"
		class="mv-create-reviews"
		data-mv-create-id="<?php echo esc_attr( $args['creation']['id'] ); ?>"
		data-mv-create-rating="<?php echo esc_attr( $args['creation']['rating'] ); ?>"
		data-mv-create-total-ratings="<?php echo esc_attr( $args['creation']['rating_count'] ); ?>"
		data-mv-rest-url="<?php echo esc_url_raw( rest_url() ); ?>"><?php
			// Server-render the stars so the block has its final height at first paint;
			// the Reviews app hydrates over this identical markup (no layout shift).
			echo \Mediavine\Create\Creations_Views::render_review_stars( $args['creation']['rating'], $args['creation']['rating_count'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted, self-built markup
		?></div>
	<!-- This is a button so it inherits theme styles -->
<?php
}

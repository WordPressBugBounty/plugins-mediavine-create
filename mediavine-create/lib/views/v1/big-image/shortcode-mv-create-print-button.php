<?php defined( 'ABSPATH' ) || exit; ?>
<?php if ( ! $args['print'] ) { ?>
	<div class="mv-create-meta-flex">

		<form
			class="mv-create-print-form"
			method="get"
			target="_blank"
			action="<?php echo esc_html( get_rest_url( null, '/mv-create/v1/creations/' . $args['creation']['id'] . '/print' ) ); ?>"
		><button
				class="mv-create-button mv-create-print-button mv-create-uppercase"
				data-mv-print="<?php echo esc_html( get_rest_url( null, '/mv-create/v1/creations/' . $args['creation']['id'] . '/print' ) ); ?>?ajax=true"
			><?php esc_html_e( 'Print', 'mediavine-create' ); ?></button></form>

		<?php if ( ! $args['print'] && $args['allow_reviews'] ) { ?>
			<div class="mv-create-reviews-flex">
				<div
					id="mv-create-<?php echo esc_attr( $args['creation']['id'] ); ?>"
					class="mv-create-reviews"
					data-mv-create-id="<?php echo esc_attr( $args['creation']['id'] ); ?>"
					data-mv-create-rating="<?php echo esc_attr( $args['creation']['rating'] ); ?>"
					data-mv-create-total-ratings="<?php echo esc_attr( $args['creation']['rating_count'] ); ?>"
					data-mv-rest-url="<?php echo esc_url_raw( rest_url() ); ?>"><?php
						// Server-render the stars so the block holds its height before hydration.
						echo \Mediavine\Create\Creations_Views::render_review_stars( $args['creation']['rating'], $args['creation']['rating_count'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted, self-built markup
					?></div>
			</div>
		<?php } ?>
	</div>
<?php
}

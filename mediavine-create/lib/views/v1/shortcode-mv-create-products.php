<?php
if ( ! $args['print'] ) {
	// Determine display mode - check card override first, then global setting
	$display_mode = ! empty( $args['creation']['products_display_mode'] )
		? $args['creation']['products_display_mode']
		: \Mediavine\Settings::get_setting( 'mv_create_products_display_mode', 'gallery' );

	// Pro gate: fall back to gallery if not Pro and list mode is selected
	if ( 'list' === $display_mode && ! \Mediavine\Create\Plugin::is_pro() ) {
		$display_mode = 'gallery';
	}

	// If display mode is 'list', use the list template
	if ( 'list' === $display_mode ) {
		include __DIR__ . '/shortcode-mv-create-products-list.php';
		return;
	}

	// Gallery mode (default) - continue with existing rendering
	// Get section title - check card override first, then global setting
	$section_title = ! empty( $args['creation']['products_section_title'] )
		? $args['creation']['products_section_title']
		: \Mediavine\Settings::get_setting( 'mv_create_products_section_title', __( 'Recommended Products', 'mediavine' ) );

	$has_products = false;
	// Default the affiliate message to the text stored in the global settings. Then check for the existence of a
	// custom field overriding the affiliate message. If it exists, use the custom field for this card.
	$global_affiliate_message = null;
	if ( ! empty( $args['creation']['create_settings']['mv_create_affiliate_message'] ) ) {
		$global_affiliate_message = $args['creation']['create_settings']['mv_create_affiliate_message'];
	}

	$affiliate_message = \Mediavine\Create\Creations_Views::get_custom_field(
		$args['creation'],
		'mv_create_affiliate_message',
		$global_affiliate_message
	);

	if ( ! empty( $args['creation']['products'] ) ) {

		// Build clean HTML for products
		ob_start();

		foreach ( $args['creation']['products'] as $product ) {

			$product = (array) $product;

			if (
				! empty( $product['link'] )
				&& ! empty( $product['title'] )
			) {

				$has_products = true;
				?>

				<li class="mv-create-products-listitem">
					<a class="mv-create-products-link" href="<?php echo esc_html( $product['link'] ); ?>" rel="nofollow noopener" target="_blank">
						<?php if ( ! $args['print'] ) { ?>
							<div class="mv-create-products-imgwrap" id="img-wrap-<?php echo esc_html( $product['id'] ); ?>">
								<?php if ( ! empty( $product['thumbnail_src'] ) ) { ?>
									<img
										class="mv-create-products-img obj-fit no_pin ggnoads"
										src="<?php echo esc_html( $product['thumbnail_src'] ); ?>"
										alt="<?php echo esc_html( $product['title'] ); ?>"
										nopin="nopin"
										height="240"
										width="240"
									/>
								<?php } ?>
							</div>
						<?php } ?>
						<div class="mv-create-products-product-name">
							<?php echo esc_html( $product['title'] ); ?>
						</div>
					</a>
				</li>

				<?php
			}
		}
		$products_list = ob_get_clean();

	}

	if ( $has_products ) {
		?>
		<div class="mv-create-products">
			<h2 class="mv-create-products-title mv-create-title-secondary"><?php echo esc_html( $section_title ); ?></h2>

			<?php if ( $affiliate_message ) { ?>
				<p class="mv-create-affiliate-disclaimer"><?php echo esc_html( $affiliate_message ); ?></p>
			<?php } ?>

			<ul class="mv-create-products-list">
				<?php echo wp_kses_post( $products_list ); ?>
			</ul>
		</div>
		<?php
	}
}

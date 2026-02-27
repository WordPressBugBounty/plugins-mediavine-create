<?php
/**
 * Products List Display Template
 * Renders products as a simple text list (like supplies/equipment)
 */

// Get section title - check card override first, then global setting
$section_title = ! empty( $args['creation']['products_section_title'] )
	? $args['creation']['products_section_title']
	: \Mediavine\Settings::get_setting( 'mv_create_products_section_title', __( 'Recommended Products', 'mediavine' ) );

$has_products = false;

// Get affiliate message
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

	// Build clean HTML for products list
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
				<a class="mv-create-products-link" href="<?php echo esc_url( $product['link'] ); ?>" rel="nofollow noopener" target="_blank">
					<?php echo esc_html( $product['title'] ); ?>
				</a>
			</li>

			<?php
		}
	}
	$products_list = ob_get_clean();

}

if ( $has_products ) {
	?>
	<div class="mv-create-products mv-create-products-text-list">
		<h2 class="mv-create-products-title mv-create-title-secondary"><?php echo esc_html( $section_title ); ?></h2>

		<?php
		$show_disclaimer = \Mediavine\Settings::get_setting( 'mv_create_products_list_show_disclaimer', true );
		if ( $affiliate_message && $show_disclaimer ) {
		?>
			<p class="mv-create-affiliate-disclaimer"><?php echo esc_html( $affiliate_message ); ?></p>
		<?php } ?>

		<ul class="mv-create-products-list">
			<?php echo wp_kses_post( $products_list ); ?>
		</ul>
	</div>
	<?php
}

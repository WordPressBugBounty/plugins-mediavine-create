<?php defined( 'ABSPATH' ) || exit; ?>
<div class="mv-list-list mv-list-list-<?php echo esc_attr( $args['creation']['layout'] ); ?>">
	<?php
	$i                        = 0;
	$open_external_in_new_tab = \Mediavine\Settings::get_setting( 'mv_create_external_link_tab' );
	$open_internal_in_new_tab = \Mediavine\Settings::get_setting( 'mv_create_internal_link_tab' );

	foreach ( $args['creation']['list_items'] as $item ) {
		do_action( 'mv_create_list_before_single', $args );

		// Section divider — bare title + description, no number, no image container.
		if ( \Mediavine\Create\Creations_Views::is_list_item_divider( $item ) ) {
			\Mediavine\Create\Creations_Views::render_list_divider( $item, $args['allowed_html'] );
			// Dividers do not consume a number — fall through without incrementing $i.
		} elseif ( 'text' === $item['content_type'] ) {
			?>
			<div id="create-list-item-<?php echo esc_attr( $item['id'] ); ?>" class="mv-list-text mv-list-single" data-mv-create-list-content-type="<?php echo esc_attr( $item['content_type'] ); ?>">
				<div class="mv-list-item-number">
					<div class="mv-list-item-number-inner">
						<?php echo esc_html( $i + 1 ); ?>
					</div>
				</div>
				<?php
				// Get image container classes
				$img_container_classes = [ 'mv-list-img-container' ];
				if ( empty( $item['thumbnail_id'] ) ) {
					$img_container_classes[] = 'mv-list-img-container-empty';
				}
				?>
				<div class="<?php echo esc_attr( implode( ' ', $img_container_classes ) ); ?>">
					<?php if ( ! empty( $item['thumbnail_id'] ) ) { ?>
						<?php echo wp_kses_post( \Mediavine\Create\Creations_Views::img( $item ) ); ?>
					<?php } ?>
				</div>
				<div class="mv-list-item-container">
					<h2 class="mv-list-single-title"><?php echo esc_html( $item['title'] ); ?></h2>
					<div class="mv-list-single-description"><?php echo wp_kses( wpautop( $item['description'] ), $args['allowed_html'] ); ?></div>
				</div>
			</div>

			<?php
			++$i;
		} else { // Link list item (external, post, page, card, product)
			$link_context         = \Mediavine\Create\Creations_Views::get_list_item_link_context( $item, $open_external_in_new_tab, $open_internal_in_new_tab );
			$target_blank         = $link_context['target_blank'];
			$target_blank_boolean = $link_context['target_blank_boolean'];
			$item_classes         = $link_context['item_classes'];
			$product_data_attr    = $link_context['product_data_attr'];
			?>

			<div id="create-list-item-<?php echo esc_attr( $item['id'] ); ?>" class="<?php echo esc_attr( $item_classes ); ?>" data-mv-create-link-target="<?php echo esc_attr( $target_blank_boolean ); ?>" data-mv-create-link-href="<?php echo esc_url( $item['url'] ); ?>" data-mv-create-list-content-type="<?php echo esc_attr( $item['content_type'] ); ?>" <?php echo wp_kses( $product_data_attr, [] ); ?>>
				<div class="mv-list-item-number">
					<div class="mv-list-item-number-inner">
						<?php echo esc_html( $i + 1 ); ?>
					</div>
				</div>
				<?php
				$pinterest_args = \Mediavine\Create\Creations_Views::build_pinterest_args( $item, $args );
				self::the_view( 'shortcode-mv-create-pin-button', $pinterest_args );

				// Get image container classes
				$img_container_classes = [ 'mv-list-img-container' ];
				if ( empty( $item['thumbnail_url'] ) ) {
					$img_container_classes[] = 'mv-list-img-container-empty';
				}
				?>
				<div class="<?php echo esc_attr( implode( ' ', $img_container_classes ) ); ?>">
					<div data-mv-create-link-href="<?php echo esc_url( $item['url'] ); ?>"
						<?php echo wp_kses( $target_blank, [] ); ?>
						<?php echo wp_kses( \Mediavine\Create\Creations_Views::rel_attribute( $item, $target_blank_boolean ), [] ); ?>
					>
						<?php echo wp_kses_post( \Mediavine\Create\Creations_Views::img( $item ) ); ?>
					</div>
				</div>
				<div class="mv-list-item-container">
					<h2 class="mv-list-single-title">
						<a
							class="mv-list-title-link"
							href="<?php echo esc_url( $item['url'] ); ?>"
							<?php echo wp_kses( $target_blank, [] ); ?>
							<?php echo wp_kses( \Mediavine\Create\Creations_Views::rel_attribute( $item, $target_blank_boolean ), [] ); ?>
						>
							<?php echo esc_html( $item['title'] ); ?>
						</a>
					</h2>
					<?php if ( ! empty( $item['extra'] ) ) { ?>
						<?php echo wp_kses_post( $item['extra'] ); ?>
					<?php } ?>
					<?php if ( ! empty( $item['thumbnail_credit'] ) ) { ?>
						<div class="mv-list-photocred">
							<strong><?php esc_html_e( 'Photo Credit:', 'mediavine-create' ); ?></strong>
							<?php echo esc_html( $item['thumbnail_credit'] ); ?>
						</div>
					<?php } ?>
					<div class="mv-list-single-description"><?php echo wp_kses( wpautop( $item['description'] ), $args['allowed_html'] ); ?></div>
					<button
						class="mv-list-link mv-to-btn"
						data-mv-create-link-href="<?php echo esc_url( $item['url'] ); ?>"
						<?php echo wp_kses( $target_blank, [] ); ?>
						<?php echo wp_kses( \Mediavine\Create\Creations_Views::rel_attribute( $item, $target_blank_boolean ), [] ); ?>
					>
						<?php echo esc_html( $item['btn_text'] ); ?>
					</button>
				</div>
			</div>

			<?php
			do_action( 'mv_create_list_after_single', $args, $i++, count( $args['creation']['list_items'] ) );
		}
	}
	?>
</div>

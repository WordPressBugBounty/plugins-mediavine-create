<?php $show_ranks = \Mediavine\Create\Creations_Views::show_list_ranks( $args['creation'] ); ?>
<div class="mv-list-list mv-list-list-<?php echo esc_attr( $args['creation']['layout'] ); ?>">
	<?php
	$i                        = 0;
	$open_external_in_new_tab = \Mediavine\Settings::get_setting( 'mv_create_external_link_tab' );
	$open_internal_in_new_tab = \Mediavine\Settings::get_setting( 'mv_create_internal_link_tab' );

	foreach ( $args['creation']['list_items'] as $item ) {
		do_action( 'mv_create_list_before_single', $args );

		// Section divider — bare title + description, spans full width.
		if ( \Mediavine\Create\Creations_Views::is_list_item_divider( $item ) ) {
			?>
			<div id="create-list-item-<?php echo esc_attr( $item['id'] ); ?>" class="mv-list-text" data-mv-create-list-content-type="divider">
				<h2 class="mv-list-single-title"><?php echo esc_html( $item['title'] ); ?></h2>
				<div class="mv-list-single-description"><?php echo wp_kses( wpautop( $item['description'] ), $args['allowed_html'] ); ?></div>
			</div>
			<?php
			// Dividers do not consume a rank — fall through without incrementing $i.
		} elseif ( 'text' === $item['content_type'] ) {
			// Alternate image/content sides per rendered item for the zig-zag.
			$flip_class = ( 0 === $i % 2 ) ? '' : ' mv-list-single-flip';
			?>
			<div id="create-list-item-<?php echo esc_attr( $item['id'] ); ?>" class="mv-list-text mv-list-single<?php echo esc_attr( $flip_class ); ?>" data-mv-create-list-content-type="<?php echo esc_attr( $item['content_type'] ); ?>">
				<?php
				$img_container_classes = [ 'mv-list-img-container' ];
				if ( empty( $item['thumbnail_id'] ) ) {
					$img_container_classes[] = 'mv-list-img-container-empty';
				}
				?>
				<div class="mv-list-img-col">
					<div class="<?php echo esc_attr( implode( ' ', $img_container_classes ) ); ?>">
						<?php if ( ! empty( $item['thumbnail_id'] ) ) { ?>
							<?php echo wp_kses_post( \Mediavine\Create\Creations_Views::img( $item ) ); ?>
						<?php } ?>
					</div>
				</div>
				<div class="mv-list-item-container">
					<?php if ( $show_ranks ) { ?>
						<div class="mv-list-rank"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></div>
					<?php } ?>
					<h2 class="mv-list-single-title"><?php echo esc_html( $item['title'] ); ?></h2>
					<div class="mv-list-single-description"><?php echo wp_kses( wpautop( $item['description'] ), $args['allowed_html'] ); ?></div>
				</div>
			</div>

			<?php
			++$i;
		} else { // Link list item (external, post, page, card, product)
			$target_blank         = '';
			$target_blank_boolean = 'false';
			// Products and external links open in new tab by default
			if ( $open_external_in_new_tab && isset( $item['content_type'] ) && ( 'external' === $item['content_type'] || 'product' === $item['content_type'] ) ) {
				$target_blank         = 'target="_blank"';
				$target_blank_boolean = 'true';
			}
			if ( $open_internal_in_new_tab && isset( $item['content_type'] ) && ( 'external' !== $item['content_type'] && 'product' !== $item['content_type'] ) ) {
				$target_blank         = 'target="_blank"';
				$target_blank_boolean = 'true';
			}

			// Alternate image/content sides per rendered item for the zig-zag.
			$flip_class = ( 0 === $i % 2 ) ? '' : ' mv-list-single-flip';

			// Add product-specific CSS class
			$item_classes = 'mv-art-link mv-list-single mv-list-single-' . esc_attr( $item['relation_id'] ) . $flip_class;
			if ( 'product' === $item['content_type'] ) {
				$item_classes .= ' mv-list-product';
			}

			// Add product ID for tracking/analytics
			$product_data_attr = '';
			if ( 'product' === $item['content_type'] && ! empty( $item['relation_id'] ) ) {
				$product_data_attr = 'data-mv-product-id="' . esc_attr( $item['relation_id'] ) . '"';
			}
			?>

			<div id="create-list-item-<?php echo esc_attr( $item['id'] ); ?>" class="<?php echo esc_attr( $item_classes ); ?>" data-mv-create-link-target="<?php echo esc_attr( $target_blank_boolean ); ?>" data-mv-create-link-href="<?php echo esc_url( $item['url'] ); ?>" data-mv-create-list-content-type="<?php echo esc_attr( $item['content_type'] ); ?>" <?php echo wp_kses( $product_data_attr, [] ); ?>>
				<?php
				$pinterest_args = \Mediavine\Create\Creations_Views::build_pinterest_args( $item, $args );

				// Get image container classes
				$img_container_classes = [ 'mv-list-img-container' ];
				if ( empty( $item['thumbnail_url'] ) ) {
					$img_container_classes[] = 'mv-list-img-container-empty';
				}
				?>
				<div class="mv-list-img-col">
					<div class="<?php echo esc_attr( implode( ' ', $img_container_classes ) ); ?>">
						<?php
						// Pin button lives inside the image container (absolutely
						// positioned) — never a direct grid child, which would throw
						// off the row's column count for items that have an image.
						\Mediavine\Create\Plugin::$views->the_view( 'shortcode-mv-create-pin-button', $pinterest_args );
						?>
						<?php if ( ! empty( $item['url'] ) ) { ?>
							<a
								class="mv-list-img-link"
								href="<?php echo esc_url( $item['url'] ); ?>"
								tabindex="-1"
								aria-hidden="true"
								<?php echo wp_kses( $target_blank, [] ); ?>
								<?php echo wp_kses( \Mediavine\Create\Creations_Views::rel_attribute( $item, $target_blank_boolean ), [] ); ?>
							>
								<?php echo wp_kses_post( \Mediavine\Create\Creations_Views::img( $item ) ); ?>
							</a>
						<?php } else { ?>
							<?php echo wp_kses_post( \Mediavine\Create\Creations_Views::img( $item ) ); ?>
						<?php } ?>
					</div>
					<?php if ( ! empty( $item['thumbnail_credit'] ) ) { ?>
						<div class="mv-list-photocred">
							<strong><?php esc_html_e( 'Photo Credit:', 'mediavine-create' ); ?></strong>
							<?php echo esc_html( $item['thumbnail_credit'] ); ?>
						</div>
					<?php } ?>
				</div>
				<div class="mv-list-item-container">
					<?php if ( $show_ranks ) { ?>
						<div class="mv-list-rank"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></div>
					<?php } ?>
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
					<div class="mv-list-single-description"><?php echo wp_kses( wpautop( $item['description'] ), $args['allowed_html'] ); ?></div>
					<?php if ( ! empty( $item['url'] ) ) { ?>
						<a
							class="mv-list-link mv-to-btn"
							href="<?php echo esc_url( $item['url'] ); ?>"
							aria-label="<?php echo esc_attr( \Mediavine\Create\Creations_Views::list_link_label( $item ) ); ?>"
							<?php echo wp_kses( $target_blank, [] ); ?>
							<?php echo wp_kses( \Mediavine\Create\Creations_Views::rel_attribute( $item, $target_blank_boolean ), [] ); ?>
						>
							<?php echo esc_html( $item['btn_text'] ); ?>
						</a>
					<?php } ?>
				</div>
			</div>

			<?php
			do_action( 'mv_create_list_after_single', $args, $i++, count( $args['creation']['list_items'] ) );
		}
	}
	?>
</div>

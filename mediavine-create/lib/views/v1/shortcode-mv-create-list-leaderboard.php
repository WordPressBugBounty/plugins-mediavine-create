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
			?>
			<div id="create-list-item-<?php echo esc_attr( $item['id'] ); ?>" class="mv-list-text mv-list-single<?php echo $show_ranks ? '' : ' mv-list-single-no-rank'; ?>" data-mv-create-list-content-type="<?php echo esc_attr( $item['content_type'] ); ?>">
				<?php if ( $show_ranks ) { ?>
					<div class="mv-list-rank"><?php echo esc_html( $i + 1 ); ?></div>
				<?php } ?>
				<?php
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
					<?php // Leaderboard descriptions are capped at ~250 characters. ?>
					<div class="mv-list-single-description"><?php echo esc_html( \Mediavine\Create\Helpers\Str::truncate( $item['description'], 250, '…' ) ); ?></div>
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

			// Add product-specific CSS class
			$item_classes = 'mv-art-link mv-list-single mv-list-single-' . esc_attr( $item['relation_id'] );
			if ( 'product' === $item['content_type'] ) {
				$item_classes .= ' mv-list-product';
			}
			if ( ! $show_ranks ) {
				$item_classes .= ' mv-list-single-no-rank';
			}

			// Add product ID for tracking/analytics
			$product_data_attr = '';
			if ( 'product' === $item['content_type'] && ! empty( $item['relation_id'] ) ) {
				$product_data_attr = 'data-mv-product-id="' . esc_attr( $item['relation_id'] ) . '"';
			}
			?>

			<div id="create-list-item-<?php echo esc_attr( $item['id'] ); ?>" class="<?php echo esc_attr( $item_classes ); ?>" data-mv-create-link-target="<?php echo esc_attr( $target_blank_boolean ); ?>" data-mv-create-link-href="<?php echo esc_url( $item['url'] ); ?>" data-mv-create-list-content-type="<?php echo esc_attr( $item['content_type'] ); ?>" <?php echo wp_kses( $product_data_attr, [] ); ?>>
				<?php if ( $show_ranks ) { ?>
					<div class="mv-list-rank"><?php echo esc_html( $i + 1 ); ?></div>
				<?php } ?>
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
						<div class="mv-list-photocred"><svg class="mv-list-photocred-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z"/></svg><?php echo esc_html( $item['thumbnail_credit'] ); ?></div>
					<?php } ?>
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
					<?php // Leaderboard descriptions are capped at ~250 characters. ?>
					<div class="mv-list-single-description"><?php echo esc_html( \Mediavine\Create\Helpers\Str::truncate( $item['description'], 250, '…' ) ); ?></div>
				</div>
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

			<?php
			do_action( 'mv_create_list_after_single', $args, $i++, count( $args['creation']['list_items'] ) );
		}
	}
	?>
</div>

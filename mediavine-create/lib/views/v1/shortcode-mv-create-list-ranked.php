<?php
// Two ranked variants share this template: 'ranked' counts DOWN to a featured
// #1 at the end, while 'ranked-top' leads with the featured #1 (ascending
// 1, 2, 3…). Both carry the mv-list-list-ranked class so they share all styling.
$ranked_ascending = ( 'ranked-top' === $args['creation']['layout'] );
?>
<div class="mv-list-list mv-list-list-ranked<?php echo $ranked_ascending ? ' mv-list-list-ranked-top' : ''; ?>">
	<?php
	$i                        = 0;
	$open_external_in_new_tab = \Mediavine\Settings::get_setting( 'mv_create_external_link_tab' );
	$open_internal_in_new_tab = \Mediavine\Settings::get_setting( 'mv_create_internal_link_tab' );

	// The rank for each item depends on the total number of rankable (non-divider)
	// items when counting down. Either way the item at rank #1 is rendered as the
	// featured "top pick" card — by rank, regardless of its content type.
	$total_ranked = 0;
	foreach ( $args['creation']['list_items'] as $count_item ) {
		if ( ! \Mediavine\Create\Creations_Views::is_list_item_divider( $count_item ) ) {
			++$total_ranked;
		}
	}

	foreach ( $args['creation']['list_items'] as $item ) {
		do_action( 'mv_create_list_before_single', $args );

		// Section divider — bare title + description, spans full width. Dividers
		// do not consume a rank.
		if ( \Mediavine\Create\Creations_Views::is_list_item_divider( $item ) ) {
			?>
			<div id="create-list-item-<?php echo esc_attr( $item['id'] ); ?>" class="mv-list-text" data-mv-create-list-content-type="divider">
				<h2 class="mv-list-single-title"><?php echo esc_html( $item['title'] ); ?></h2>
				<div class="mv-list-single-description"><?php echo wp_kses( wpautop( $item['description'] ), $args['allowed_html'] ); ?></div>
			</div>
			<?php
			continue;
		}

		// Ascending leads with #1 (i is the 0-based rankable index); the countdown
		// variant makes the last rankable item #1.
		$rank   = $ranked_ascending ? ( $i + 1 ) : ( $total_ranked - $i );
		$is_top = ( 1 === $rank );

		// Text items have no URL, so they omit the link wrapper and CTA button —
		// but they still get a rank and (at #1) the featured card, just like any
		// other item. Everything else (external/post/page/card/product) links.
		$is_text = ( 'text' === $item['content_type'] );

		$target_blank         = '';
		$target_blank_boolean = 'false';
		if ( ! $is_text ) {
			// Products and external links open in a new tab by default.
			if ( $open_external_in_new_tab && ( 'external' === $item['content_type'] || 'product' === $item['content_type'] ) ) {
				$target_blank         = 'target="_blank"';
				$target_blank_boolean = 'true';
			}
			if ( $open_internal_in_new_tab && ( 'external' !== $item['content_type'] && 'product' !== $item['content_type'] ) ) {
				$target_blank         = 'target="_blank"';
				$target_blank_boolean = 'true';
			}
		}

		$item_classes = 'mv-list-single mv-list-single-' . esc_attr( $item['relation_id'] );
		if ( $is_text ) {
			$item_classes .= ' mv-list-text';
		} else {
			$item_classes = 'mv-art-link ' . $item_classes;
		}
		if ( $is_top ) {
			$item_classes .= ' mv-list-single-top';
		}
		if ( 'product' === $item['content_type'] ) {
			$item_classes .= ' mv-list-product';
		}

		// Product ID for tracking/analytics.
		$product_data_attr = '';
		if ( 'product' === $item['content_type'] && ! empty( $item['relation_id'] ) ) {
			$product_data_attr = 'data-mv-product-id="' . esc_attr( $item['relation_id'] ) . '"';
		}

		// Link data attributes only apply to items that actually link out.
		$link_data_attr = '';
		if ( ! $is_text ) {
			$link_data_attr = 'data-mv-create-link-target="' . esc_attr( $target_blank_boolean ) . '" data-mv-create-link-href="' . esc_url( $item['url'] ) . '"';
		}

		$pinterest_args = \Mediavine\Create\Creations_Views::build_pinterest_args( $item, $args );

		$img_container_classes = [ 'mv-list-img-container' ];
		if ( empty( $item['thumbnail_url'] ) && empty( $item['thumbnail_id'] ) ) {
			$img_container_classes[] = 'mv-list-img-container-empty';
		}

		if ( $is_top ) {
			// ---- Featured #1 "top pick" card -----------------------------------
			?>
			<div id="create-list-item-<?php echo esc_attr( $item['id'] ); ?>" class="<?php echo esc_attr( $item_classes ); ?>" <?php echo wp_kses( $link_data_attr, [] ); ?> data-mv-create-list-content-type="<?php echo esc_attr( $item['content_type'] ); ?>" <?php echo wp_kses( $product_data_attr, [] ); ?>>
				<div class="mv-list-top-banner"><span class="mv-list-top-rank">#<?php echo esc_html( $rank ); ?></span> <?php esc_html_e( 'Top Pick', 'mediavine-create' ); ?></div>
				<div class="mv-list-top-body">
					<div class="<?php echo esc_attr( implode( ' ', $img_container_classes ) ); ?>">
						<?php
						// Pin button lives inside the image container (absolutely
						// positioned) — never a direct child.
						\Mediavine\Create\Plugin::$views->the_view( 'shortcode-mv-create-pin-button', $pinterest_args );
						if ( ! $is_text ) {
							?>
							<div data-mv-create-link-href="<?php echo esc_url( $item['url'] ); ?>"
								<?php echo wp_kses( $target_blank, [] ); ?>
								<?php echo wp_kses( \Mediavine\Create\Creations_Views::rel_attribute( $item, $target_blank_boolean ), [] ); ?>
							>
								<?php echo wp_kses_post( \Mediavine\Create\Creations_Views::img( $item ) ); ?>
							</div>
							<?php
						} else {
							echo wp_kses_post( \Mediavine\Create\Creations_Views::img( $item ) );
						}
						?>
						<?php if ( ! empty( $item['thumbnail_credit'] ) ) { ?>
							<div class="mv-list-photocred mv-list-photocred-overlay"><?php echo esc_html( $item['thumbnail_credit'] ); ?></div>
						<?php } ?>
					</div>
					<div class="mv-list-item-container">
						<h2 class="mv-list-single-title">
							<?php if ( ! $is_text ) { ?>
								<a
									class="mv-list-title-link"
									href="<?php echo esc_url( $item['url'] ); ?>"
									<?php echo wp_kses( $target_blank, [] ); ?>
									<?php echo wp_kses( \Mediavine\Create\Creations_Views::rel_attribute( $item, $target_blank_boolean ), [] ); ?>
								>
									<?php echo esc_html( $item['title'] ); ?>
								</a>
							<?php } else { ?>
								<?php echo esc_html( $item['title'] ); ?>
							<?php } ?>
						</h2>
						<?php if ( ! empty( $item['extra'] ) ) { ?>
							<?php echo wp_kses_post( $item['extra'] ); ?>
						<?php } ?>
						<?php // The featured card shows the full description; the compact rows cap theirs. ?>
						<div class="mv-list-single-description"><?php echo wp_kses( wpautop( $item['description'] ), $args['allowed_html'] ); ?></div>
						<?php if ( ! $is_text ) { ?>
							<button
								class="mv-list-link mv-to-btn"
								data-mv-create-link-href="<?php echo esc_url( $item['url'] ); ?>"
								<?php echo wp_kses( $target_blank, [] ); ?>
								<?php echo wp_kses( \Mediavine\Create\Creations_Views::rel_attribute( $item, $target_blank_boolean ), [] ); ?>
							>
								<?php echo esc_html( $item['btn_text'] ); ?>
							</button>
						<?php } ?>
					</div>
				</div>
			</div>
			<?php
		} else {
			// ---- Compact ranked row --------------------------------------------
			?>
			<div id="create-list-item-<?php echo esc_attr( $item['id'] ); ?>" class="<?php echo esc_attr( $item_classes ); ?>" <?php echo wp_kses( $link_data_attr, [] ); ?> data-mv-create-list-content-type="<?php echo esc_attr( $item['content_type'] ); ?>" <?php echo wp_kses( $product_data_attr, [] ); ?>>
				<div class="mv-list-rank"><?php echo esc_html( $rank ); ?></div>
				<div class="mv-list-img-col">
					<div class="<?php echo esc_attr( implode( ' ', $img_container_classes ) ); ?>">
						<?php
						// Pin button lives inside the image container (absolutely
						// positioned) — never a direct grid child, which would throw
						// off the row's column count for items that have an image.
						\Mediavine\Create\Plugin::$views->the_view( 'shortcode-mv-create-pin-button', $pinterest_args );
						if ( ! $is_text ) {
							?>
							<div data-mv-create-link-href="<?php echo esc_url( $item['url'] ); ?>"
								<?php echo wp_kses( $target_blank, [] ); ?>
								<?php echo wp_kses( \Mediavine\Create\Creations_Views::rel_attribute( $item, $target_blank_boolean ), [] ); ?>
							>
								<?php echo wp_kses_post( \Mediavine\Create\Creations_Views::img( $item ) ); ?>
							</div>
							<?php
						} else {
							echo wp_kses_post( \Mediavine\Create\Creations_Views::img( $item ) );
						}
						?>
					</div>
					<?php if ( ! empty( $item['thumbnail_credit'] ) ) { ?>
						<div class="mv-list-photocred"><svg class="mv-list-photocred-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z"/></svg><?php echo esc_html( $item['thumbnail_credit'] ); ?></div>
					<?php } ?>
				</div>
				<div class="mv-list-item-container">
					<h2 class="mv-list-single-title">
						<?php if ( ! $is_text ) { ?>
							<a
								class="mv-list-title-link"
								href="<?php echo esc_url( $item['url'] ); ?>"
								<?php echo wp_kses( $target_blank, [] ); ?>
								<?php echo wp_kses( \Mediavine\Create\Creations_Views::rel_attribute( $item, $target_blank_boolean ), [] ); ?>
							>
								<?php echo esc_html( $item['title'] ); ?>
							</a>
						<?php } else { ?>
							<?php echo esc_html( $item['title'] ); ?>
						<?php } ?>
					</h2>
					<?php if ( ! empty( $item['extra'] ) ) { ?>
						<?php echo wp_kses_post( $item['extra'] ); ?>
					<?php } ?>
					<?php // Like Leaderboard, the compact rows show a short, capped description. ?>
					<div class="mv-list-single-description"><?php echo esc_html( \Mediavine\Create\Helpers\Str::truncate( $item['description'], 250, '…' ) ); ?></div>
				</div>
				<?php if ( ! $is_text ) { ?>
					<button
						class="mv-list-link mv-to-btn"
						data-mv-create-link-href="<?php echo esc_url( $item['url'] ); ?>"
						<?php echo wp_kses( $target_blank, [] ); ?>
						<?php echo wp_kses( \Mediavine\Create\Creations_Views::rel_attribute( $item, $target_blank_boolean ), [] ); ?>
					>
						<?php echo esc_html( $item['btn_text'] ); ?>
					</button>
				<?php } ?>
			</div>
			<?php
		}

		do_action( 'mv_create_list_after_single', $args, $i++, count( $args['creation']['list_items'] ) );
	}
	?>
</div>

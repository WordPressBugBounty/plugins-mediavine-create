<div class="mv-list-list mv-list-list-<?php echo esc_attr( $args['creation']['layout'] ); ?>">
	<div class="mv-list-list-grid-inner">
		<?php
		$i                        = 0;
		$r                        = 1;
		$total_items              = count( $args['creation']['list_items'] );
		$open_external_in_new_tab = \Mediavine\Settings::get_setting( 'mv_create_external_link_tab' );
		$open_internal_in_new_tab = \Mediavine\Settings::get_setting( 'mv_create_internal_link_tab' );

		foreach ( $args['creation']['list_items'] as $item ) {
			do_action( 'mv_create_list_before_single', $args );

			// Text list item
			if ( 'text' === $item['content_type'] ) {
				// It looks weird to have the ad split the title and items,
				// so if an ad should be displayed, do it above the title
				if ( 0 !== $i && 1 === $i % 2 ) {
					// send the row `$r` count and the total items to the ad-inserter
					do_action( 'mv_create_list_after_row', $args, $r, $total_items );
				}
				// increment the row, counter, and total items
				// text items count as 2 items since they take up one full row
				++$r;
				++$i;
				++$total_items;
				// ensure the row ends with an odd index number
				if ( 0 !== $i % 2 ) {
					++$i;
				}
				?>
				<div class="mv-list-text">
					<h2 class="mv-list-single-title"><?php echo esc_html( $item['title'] ); ?></h2>
					<div class="mv-list-single-description"><?php echo wp_kses( wpautop( $item['description'] ), $args['allowed_html'] ); ?></div>
				</div>
				<?php
			} else { // Link list item
				$target_blank         = '';
				$target_blank_boolean = 'false';
				if ( $open_external_in_new_tab && isset( $item['content_type'] ) && ( 'external' === $item['content_type'] ) ) {
					$target_blank         = 'target="_blank"';
					$target_blank_boolean = 'true';
				}
				if ( $open_internal_in_new_tab && isset( $item['content_type'] ) && ( 'external' !== $item['content_type'] ) ) {
					$target_blank         = 'target="_blank"';
					$target_blank_boolean = 'true';
				}
				?>

				<div class="mv-art-link mv-list-single mv-list-single-<?php echo esc_attr( $item['relation_id'] ); ?>" data-mv-create-link-target="<?php echo esc_attr( $target_blank_boolean ); ?>" data-mv-create-link-href="<?php echo esc_attr( $item['url'] ); ?>" data-mv-create-list-content-type="<?php echo esc_attr( $item['content_type'] ); ?>">
					<div class="mv-list-img-container">
						<?php
						$pinterest_args = \Mediavine\Create\Creations_Views::build_pinterest_args( $item, $args );
						self::the_view( 'shortcode-mv-create-pin-button', $pinterest_args );
						?>
						<div data-mv-create-link-href="<?php echo esc_attr( $item['url'] ); ?>"
							<?php echo wp_kses( $target_blank, [] ); ?>
							<?php echo wp_kses( \Mediavine\Create\Creations_Views::rel_attribute( $item, $target_blank_boolean ), [] ); ?>
						>
							<?php echo wp_kses_post( \Mediavine\Create\Creations_Views::img( $item ) ); ?>
						</div>
					</div>
					<h2 class="mv-list-single-title">
						<a
							class="mv-list-title-link"
							href="<?php echo esc_attr( $item['url'] ); ?>"
							<?php echo wp_kses( $target_blank, [] ); ?>
							<?php echo wp_kses( \Mediavine\Create\Creations_Views::rel_attribute( $item, $target_blank_boolean ), [] ); ?>
						>
						<?php echo esc_html( $item['title'] ); ?>
						</a>
					</h2>
					<?php echo wp_kses_post( $item['extra'] ); ?>
					<?php if ( ! empty( $item['thumbnail_credit'] ) ) { ?>
						<div class="mv-list-photocred">
							<strong><?php esc_html_e( 'Photo Credit:', 'mediavine' ); ?></strong>
							<?php echo esc_html( $item['thumbnail_credit'] ); ?>
						</div>
					<?php } ?>
					<div class="mv-list-single-description"><?php echo wp_kses( wpautop( $item['description'] ), $args['allowed_html'] ); ?></div>
					<div>
						<button
							class="mv-list-link mv-to-btn"
							data-mv-create-link-href="<?php echo esc_attr( $item['url'] ); ?>"
							<?php echo wp_kses( $target_blank, [] ); ?>
							<?php echo wp_kses( \Mediavine\Create\Creations_Views::rel_attribute( $item, $target_blank_boolean ), [] ); ?>
						>
							<?php echo esc_html( $item['btn_text'] ); ?>
						</button>
					</div>
				</div>

				<?php
				// if there is a remainder, we know the index is odd. Because the counter is 0-indexed,
				// this actually means that the item is the 2nd of a pair of items, completing a row.
				if ( 1 === $i % 2 ) {
					// send the row `$r` count and the total items to the ad-inserter
					do_action( 'mv_create_list_after_row', $args, $r, $total_items );
					// increment the row
					$r++;
				}
				// increment the index counter outside of any logic, for readability
				$i++;
			}
		}
		?>
	</div>
</div>

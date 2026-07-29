<?php
defined( 'ABSPATH' ) || exit;
if ( ! empty( $args['creation']['ingredients'] ) ) {
	$group_index = 0;
	?>
	<div class="mv-create-ingredients">
		<h2 class="mv-create-ingredients-title mv-create-title-secondary"><?php esc_html_e( 'Ingredients', 'mediavine-create' ); ?></h2>

		<?php foreach ( $args['creation']['ingredients'] as $group => $ingredients ) { ?>
			<?php
				if ( ! count( $ingredients ) ) {
					continue;
				}
				$has_group_name = ! in_array( $group, [ 'mv-has-no-group', '_empty_' ], true );
			?>
			<div class="mv-create-ingredient-group" data-ingredient-group="<?php echo esc_attr( $group_index ); ?>">
				<?php if ( $has_group_name ) { ?>
					<div class="mv-create-ingredient-group-header">
						<h3><?php echo esc_html( $group ); ?></h3>
						<button type="button" class="mv-create-ingredient-group-toggle" aria-expanded="true" style="display: none;">
							<span class="screen-reader-text"><?php esc_html_e( 'Toggle ingredient group', 'mediavine-create' ); ?></span>
						</button>
					</div>
				<?php } ?>
				<ul class="mv-create-ingredient-list">
					<?php
					$ingredient_index = 0;
					foreach ( $ingredients as $ingredient ) {
						// Force object to array
						$ingredient = (array) $ingredient;
						?>
						<li data-ingredient-index="<?php echo esc_attr( $ingredient_index ); ?>"<?php if ( ! empty( $ingredient['id'] ) ) : ?> data-ingredient-id="<?php echo esc_attr( $ingredient['id'] ); ?>"<?php endif; ?>>
							<?php
							if ( ! empty( $ingredient['original_text'] ) ) {
								if ( ! empty( $ingredient['link'] ) ) {
									\Mediavine\Create\Creations_Views::render_supply_link( $ingredient );
								} else {
									echo wp_kses_post( $ingredient['original_text'] );
								}
							}
							?>
						</li>
						<?php
						$ingredient_index++;
					}
					?>
				</ul>
			</div>
			<?php
			$group_index++;
		} ?>
	</div>
<?php
}

<?php defined( 'ABSPATH' ) || exit; ?>
<?php if ( ! empty( $args['creation']['materials'] ) ) { ?>
	<div class="mv-create-ingredients">
		<h2 class="mv-create-ingredients-title mv-create-title-secondary"><?php esc_html_e( 'Materials', 'mediavine-create' ); ?></h2>

		<?php foreach ( $args['creation']['materials'] as $group => $materials ) { ?>
			<?php
				if ( ! count( $materials ) ) {
					continue;
				}
			?>
			<?php if ( ! in_array( $group, [ 'mv-has-no-group', '_empty_' ], true ) ) { ?>
				<h3><?php echo esc_html( $group ); ?></h3>
			<?php } ?>
			<ul>
				<?php
				foreach ( $materials as $material ) {
					// Force object to array
					$material = (array) $material;
					?>
					<li>
						<?php
						if ( ! empty( $material['original_text'] ) ) {
							if ( ! empty( $material['link'] ) ) {
								\Mediavine\Create\Creations_Views::render_supply_link( $material );
							} else {
								echo wp_kses_post( $material['original_text'] );
							}
						}
						?>
					</li>
				<?php } ?>
			</ul>
		<?php } ?>
	</div>
<?php
}

if ( ! empty( $args['creation']['tools'] ) ) {
?>
	<div class="mv-create-ingredients">
		<h2 class="mv-create-ingredients-title mv-create-title-secondary"><?php esc_html_e( 'Tools', 'mediavine-create' ); ?></h2>

		<?php foreach ( $args['creation']['tools'] as $group => $tools ) { ?>
			<?php
				if ( ! count( $tools ) ) {
					continue;
				}
			?>
			<?php if ( ! in_array( $group, [ 'mv-has-no-group', '_empty_' ], true ) ) { ?>
				<h3><?php echo esc_html( $group ); ?></h3>
			<?php } ?>
			<ul>
				<?php
				foreach ( $tools as $tool ) {
					// Force object to array
					$tool = (array) $tool;
					?>
					<li>
						<?php
						if ( ! empty( $tool['original_text'] ) ) {
							if ( ! empty( $tool['link'] ) ) {
								\Mediavine\Create\Creations_Views::render_supply_link( $tool );
							} else {
								echo wp_kses_post( $tool['original_text'] );
							}
						}
						?>
					</li>
				<?php } ?>
			</ul>
		<?php } ?>
	</div>
<?php
}

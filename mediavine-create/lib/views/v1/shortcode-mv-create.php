<?php
if ( $args['creation'] ) {
	$custom_class = \Mediavine\Create\Creations_Views::get_custom_field( $args['creation'], 'class' );

	// Build consolidated config for Create Studio widgets
	$cs_config = [];

	// Servings adjustment config
	if (
		'recipe' === $args['creation']['type'] &&
		\Mediavine\Settings::get_setting( 'mv_create_enable_servings_adjustment', false ) &&
		\Mediavine\Create\GateKeeper::can_access( \Mediavine\Create\GateKeeper::FEATURE_SERVINGS_ADJUSTMENT )
	) {
		$servings_label           = \Mediavine\Settings::get_setting( 'mv_create_servings_adjustment_label', 'Adjust Servings' );
		$cs_config['servingsAdjustment'] = [
			'enabled'           => true,
			'label'             => $servings_label,
			'defaultMultiplier' => 1,
		];
	}

	// Unit conversion config
	if (
		'recipe' === $args['creation']['type'] &&
		\Mediavine\Settings::get_setting( 'mv_create_enable_unit_conversion', false ) &&
		\Mediavine\Create\GateKeeper::can_access( \Mediavine\Create\GateKeeper::FEATURE_UNIT_CONVERSION )
	) {
		$uc_label = \Mediavine\Settings::get_setting( 'mv_create_unit_conversion_label', 'Unit Conversion' );
		$uc_default_system = \Mediavine\Settings::get_setting( 'mv_create_unit_conversion_default_system', 'auto' );
		$cs_config['unitConversion'] = [
			'enabled'        => true,
			'label'          => $uc_label,
			'default_system' => $uc_default_system,
			'source_system'  => 'us_customary',
			'conversions'    => new \stdClass(),
		];
		// Merge in pre-computed conversion data if available
		if ( ! empty( $args['creation']['unit_conversions'] ) ) {
			$cs_config['unitConversion'] = array_merge(
				$cs_config['unitConversion'],
				$args['creation']['unit_conversions']
			);
		}
	}

	$cs_config_attr = '';
	if ( ! empty( $cs_config ) ) {
		$cs_config['widgetLayout'] = \Mediavine\Settings::get_setting( 'mv_create_widget_toolbar_layout', 'toolbar' );
		$cs_config['showLabels']   = (bool) \Mediavine\Settings::get_setting( 'mv_create_show_widget_labels', false );
		$cs_config_attr = ' data-cs-config="' . esc_attr( wp_json_encode( $cs_config ) ) . '"';
	}

	/**
	 * mv_create_card_before hook.
	 *
	 * @hooked mv_creation_json_ld - 10
	 */
	do_action( 'mv_create_card_before', $args );

	$card_inline_style = trim( 'position: relative; ' . \Mediavine\Create\Creations_Views::get_card_inline_style() );
	?>
	<section id="mv-creation-<?php echo esc_attr( $args['creation']['id'] ); ?>" class="<?php echo esc_attr( $args['creation']['classes'] ); ?> <?php echo esc_attr( $custom_class ); ?>"<?php echo $cs_config_attr; ?> style="<?php echo esc_attr( $card_inline_style ); ?>">
		<?php
		/**
		 * mv_create_card_before_wrapper hook.
		 */
		do_action( 'mv_create_card_before_wrapper', $args );
		?>

		<div class="mv-create-wrapper">

			<?php
			/**
			 * mv_create_card_before_header hook.
			 */
			do_action( 'mv_create_card_before_header', $args );
			?>

			<header class="mv-create-header">
				<?php
				/**
				 * mv_create_card_header hook.
				 *
				 * @hooked mv_creation_title - 10
				 * @hooked mv_create_pin_button - 20
				 * @hooked mv_creation_image_container - 30
				 * @hooked mv_creation_description - 40
				 */
				do_action( 'mv_create_card_header', $args );
				?>
			</header>

			<?php
			/**
			 * mv_create_card_content hook.
			 *
			 * @hooked mv_creation_times - 10
			 * @hooked mv_creation_ad_div - 20
			 * @hooked mv_creation_ingredients - 30
			 * @hooked mv_creation_instructions - 40
			 * @hooked mv_creation_notes - 50
			 * @hooked mv_creation_video - 60
			 * @hooked mv_creation_products - 70
			 * @hooked mv_creation_nutrition - 80
			 * @hooked mv_creation_social - 90
			 */
			do_action( 'mv_create_card_content', $args );
			?>

		</div>

		<footer class="mv-create-footer">
			<?php
			/**
			 * mv_create_card_footer hook.
			 *
			 * @hooked mv_creation_footer - 10
			 */
			do_action( 'mv_create_card_footer', $args );
			?>
		</footer>

		<?php
		/**
		 * mv_create_card_after_footer hook.
		 */
		do_action( 'mv_create_card_after_footer', $args );
		?>

		<?php if ( empty( $args['print'] ) ) : ?>
		<div class="mv-create-checklists" data-creation-id="<?php echo esc_attr( $args['creation']['id'] ); ?>"></div>
		<?php endif; ?>

	</section>

	<?php
	/**
	 * mv_create_card_after hook.
	 */
	do_action( 'mv_create_card_after', $args );

}

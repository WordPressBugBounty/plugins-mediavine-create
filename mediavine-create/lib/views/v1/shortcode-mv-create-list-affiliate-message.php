<?php
$show_affiliate_message = \Mediavine\Create\Creations_Views::get_custom_field(
	$args['creation'],
	'mv_create_show_list_affiliate_message',
	false
);

if ( 'list' === $args['creation']['type'] && ! empty( $show_affiliate_message ) ) { ?>
	<?php
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

	?>
	<div class="mv-create-affiliate-disclaimer">
		<?php echo esc_html( $affiliate_message ); ?>
	</div>
<?php } ?>

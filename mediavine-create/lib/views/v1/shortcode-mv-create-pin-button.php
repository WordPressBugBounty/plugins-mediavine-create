<?php
// All the reasons we shouldn't output the Pin button:
if (
	// This is a print layout
	$args['print'] ||
	// Pinterest display set and is false (we added this setting later so some cards don't have the setting)
	( isset( $args['creation']['pinterest_display'] ) && ! $args['creation']['pinterest_display'] ) ||
	// We have no pinterest URL
	empty( $args['pinterest']['url'] ) ||
	// The URL points to Pinterest, which Pinterest doesn't allow
	strpos( $args['pinterest']['url'], '//www.pinterest.' ) ||
	strpos( $args['pinterest']['url'], '//pinterest.' ) ||
	// There's no image set to pin
	empty( $args['pinterest']['img'] )
) {
	return;
}
?>
<div
	class="mv-pinterest-btn <?php echo esc_attr( $args['creation']['pinterest_class'] ); ?>"
	data-mv-pinterest-desc="<?php echo esc_attr( rawurlencode( $args['pinterest']['description'] ) ); ?>"
	data-mv-pinterest-img-src="<?php echo esc_attr( rawurlencode( $args['pinterest']['img'] ) ); ?>"
	data-mv-pinterest-url="<?php echo esc_attr( rawurlencode( $args['pinterest']['url'] ) ); ?>"
></div>

<?php
defined( 'ABSPATH' ) || exit;
$times = \Mediavine\Create\Creations_Views::prep_creation_times( $args['creation'] );
$style = $args['style'] ?? 'square';

// Any unknown or custom style falls back to the square markup.
// Styles using the lowercase em label with a colon and an uppercase time value.
$has_em_labels = in_array( $style, [ 'big-image', 'dark', 'centered', 'centered-dark', 'editorial', 'modern' ], true );
// Styles rendering a leading yield row before the times.
$has_yield_row = in_array( $style, [ 'centered', 'centered-dark', 'editorial', 'modern' ], true );
// Styles adding the xl container class.
$xl_class = in_array( $style, [ 'editorial', 'modern' ], true ) ? ' mv-create-times-xl' : '';
// centered / centered-dark historically indented the yield row one level deeper.
$yield_indent = in_array( $style, [ 'centered', 'centered-dark' ], true ) ? "\t\t\t" : "\t\t";

if ( ! empty( $times ) || ( $has_yield_row && ! empty( $args['creation']['yield'] ) ) ) {
?>

<div class="mv-create-times mv-create-times-<?php echo esc_attr( count( $times ) ); ?><?php echo esc_attr( $xl_class ); ?>">

<?php if ( $has_yield_row ) { ?>
	<?php if ( ! empty( $args['creation']['yield'] ) ) { ?>
<?php
		echo esc_html( $yield_indent ) . '<div class="mv-create-time mv-create-time-yield">' . "\n";
		echo esc_html( $yield_indent ) . "\t" . '<em class="mv-create-time-label mv-create-lowercase mv-create-strong">';
		esc_html_e( 'Yield', 'mediavine-create' );
		echo ': </em>' . "\n";
		echo esc_html( $yield_indent ) . "\t" . '<span class="mv-create-time-format mv-create-uppercase">' . esc_html( $args['creation']['yield'] ) . '</span>' . "\n";
		echo esc_html( $yield_indent ) . '</div>' . "\n";
?>
	<?php } ?>

	<?php if ( ! empty( $times ) ) { ?>
		<?php foreach ( $times as $time ) { ?>
			<div class="mv-create-time mv-create-time-<?php echo esc_attr( $time['class'] ); ?>">
				<em class="mv-create-time-label mv-create-lowercase mv-create-strong"><?php echo esc_html( $time['label'] ); ?>: </em>
				<span class="mv-create-time-format mv-create-uppercase"><?php echo wp_kses_post( $time['time'] ); ?></span>
			</div>
		<?php } ?>
	<?php } ?>

<?php } else { ?>
	<?php foreach ( $times as $time ) { ?>
		<div class="mv-create-time mv-create-time-<?php echo esc_attr( $time['class'] ); ?>">
			<?php if ( $has_em_labels ) { ?>
			<em class="mv-create-time-label mv-create-lowercase mv-create-strong"><?php echo esc_html( $time['label'] ); ?>: </em>
			<span class="mv-create-time-format mv-create-uppercase"><?php echo wp_kses_post( $time['time'] ); ?></span>
			<?php } else { ?>
			<strong class="mv-create-time-label mv-create-uppercase mv-create-strong"><?php echo esc_html( $time['label'] ); ?></strong>
			<span class="mv-create-time-format"><?php echo wp_kses_post( $time['time'] ); ?></span>
			<?php } ?>
		</div>
	<?php } ?>
<?php } ?>

</div>
<?php
}

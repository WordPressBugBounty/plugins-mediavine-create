<?php
$video          = (array) json_decode( $args['creation']['video'] ?: '{}' );
$external_video = (array) json_decode( $args['creation']['external_video'] ?: '{}' );

if ( ( ! empty( $video['include'] ) || ! empty( $video['display'] ) ) && ! $args['print'] && class_exists( '\Mediavine\MCP\Video' ) ) { ?>
	<div class="mv-create-video">
		<?php echo '[mv_video key=' . esc_attr( $video['key'] ) . ' volume=' . esc_attr( $video['volume'] ) . ' ratio=' . esc_attr( $video['aspectRatio'] ) . ' jsonld="false"]'; ?>
	</div>
	<?php
} elseif (
	( empty( $video ) || ! class_exists( '\Mediavine\MCP\Video' ) ) &&
	( ! empty( $external_video['include'] ) || ! empty( $external_video['display'] ) ) &&
	'YOUTUBE' === $external_video['source']
) {
	?>
		<div class="mv-create-video">
			<div class="mv-create-iframe">
				<iframe src="https://www.youtube.com/embed/<?php echo esc_attr( $external_video['id'] ); ?>" frameborder="0" loading="lazy" allow="autoplay; encrypted-media" allowfullscreen></iframe>
			</div>
		</div>
	<?php
} elseif ( ! empty( $args['creation']['video_shortcode'] ) && ! $args['print'] ) {
	?>
		<div class="mv-create-video">
			<?php echo do_shortcode( wp_kses_post( $args['creation']['video_shortcode'] ) ); ?>
		</div>
	<?php
}

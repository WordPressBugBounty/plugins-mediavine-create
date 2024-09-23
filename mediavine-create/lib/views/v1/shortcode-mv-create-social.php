<?php
if ( ! empty( $args['creation']['social_footer'] ) ) { ?>
	<div class="mv-create-social">
		<?php do_action( 'mv_create_card_social_icon', $args ); ?>
		<div class="mv-create-social-content">
			<h2 class="mv-create-social-title mv-create-title-secondary"><?php echo esc_html( $args['creation']['social_cta_title'] ); ?></h2>
			<p class="mv-create-social-body"><?php echo wp_kses( $args['creation']['social_cta_body'], $args['creation']['social_body_kses'] ); ?></p>
		</div>
	</div>
<?php
}

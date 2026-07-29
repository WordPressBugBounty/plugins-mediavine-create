<?php

namespace Mediavine\Create\API\V1\CreationsSchema;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function get_single() {
	return [
		'id'                       => [
			'description' => esc_html__( 'Unique identifier for the card.', 'mediavine-create' ),
			'type'        => 'int',
			'context'     => [ 'view', 'edit', 'embed' ],
			'readonly'    => true,
		],
		'title'                    => [
			'description' => esc_html__( 'Title of the card.', 'mediavine-create' ),
			'type'        => 'string',
		],
		'object_id'                => [
			'description' => esc_html__( '.', 'mediavine-create' ),
			'type'        => 'string',
		],
		'type'                     => [
			'description' => esc_html__( 'One of \'recipe\' or \'diy\'.', 'mediavine-create' ),
			'type'        => 'string',
		],
		'author'                   => [
			'description' => esc_html__( 'Name of the author.', 'mediavine-create' ),
			'type'        => 'string',
		],
		'created'                  => [
			'description' => esc_html__( 'Timestamp of creation.', 'mediavine-create' ),
			'type'        => 'string',
		],
		'modified'                 => [
			'description' => esc_html__( 'Timestamp of last edit.', 'mediavine-create' ),
			'type'        => 'string',
		],
		'description'              => [
			'description' => esc_html__( 'HTML description.', 'mediavine-create' ),
			'type'        => 'string',
		],
		'instructions'             => [
			'description' => esc_html__( 'HTML instructions.', 'mediavine-create' ),
			'type'        => 'string',
		],
		'instructions_with_ads'    => [
			'description' => esc_html__( 'HTM instructions with ads inline.', 'mediavine-create' ),
			'type'        => 'string',
		],
		'notes'                    => [
			'description' => esc_html__( 'Notes.', 'mediavine-create' ),
			'type'        => 'string',
		],
		'canonical_post_permalink' => [
			'description' => esc_html__( 'Fully qualified URL of post.', 'mediavine-create' ),
			'type'        => 'string',
		],
		// INCOMPLETE
	];
}


<?php

namespace Mediavine\Create\API\V1\CreationsSchema;

function get_single() {
	return [
		'id'                       => [
			'description' => esc_html__( 'Unique identifier for the card.', 'mediavine' ),
			'type'        => 'int',
			'context'     => [ 'view', 'edit', 'embed' ],
			'readonly'    => true,
		],
		'title'                    => [
			'description' => esc_html__( 'Title of the card.', 'mediavine' ),
			'type'        => 'string',
		],
		'object_id'                => [
			'description' => esc_html__( '.', 'mediavine' ),
			'type'        => 'string',
		],
		'type'                     => [
			'description' => esc_html__( 'One of \'recipe\' or \'diy\'.', 'mediavine' ),
			'type'        => 'string',
		],
		'author'                   => [
			'description' => esc_html__( 'Name of the author.', 'mediavine' ),
			'type'        => 'string',
		],
		'created'                  => [
			'description' => esc_html__( 'Timestamp of creation.', 'mediavine' ),
			'type'        => 'string',
		],
		'modified'                 => [
			'description' => esc_html__( 'Timestamp of last edit.', 'mediavine' ),
			'type'        => 'string',
		],
		'description'              => [
			'description' => esc_html__( 'HTML description.', 'mediavine' ),
			'type'        => 'string',
		],
		'instructions'             => [
			'description' => esc_html__( 'HTML instructions.', 'mediavine' ),
			'type'        => 'string',
		],
		'instructions_with_ads'    => [
			'description' => esc_html__( 'HTM instructions with ads inline.', 'mediavine' ),
			'type'        => 'string',
		],
		'notes'                    => [
			'description' => esc_html__( 'Notes.', 'mediavine' ),
			'type'        => 'string',
		],
		'canonical_post_permalink' => [
			'description' => esc_html__( 'Fully qualified URL of post.', 'mediavine' ),
			'type'        => 'string',
		],
		// INCOMPLETE
	];
}


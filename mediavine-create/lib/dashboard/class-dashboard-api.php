<?php
namespace Mediavine\Create;

/**
 * Dashboard API - REST endpoints for the Create Dashboard.
 *
 * @package Mediavine\Create
 */
class Dashboard_API {

	/**
	 * API route namespace.
	 *
	 * @var string
	 */
	private $api_route = 'mv-create';

	/**
	 * API version.
	 *
	 * @var string
	 */
	private $api_version = 'v1';

	/**
	 * Initialize the Dashboard API.
	 */
	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		$namespace = $this->api_route . '/' . $this->api_version;

		register_rest_route(
			$namespace, '/dashboard/stats', [
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_stats' ],
					'permission_callback' => function () {
						return \Mediavine\Permissions::is_user_authorized();
					},
				],
			]
		);

		register_rest_route(
			$namespace, '/dashboard/checklist', [
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_checklist' ],
					'permission_callback' => function () {
						return \Mediavine\Permissions::is_user_authorized();
					},
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'update_checklist' ],
					'permission_callback' => function () {
						return \Mediavine\Permissions::is_user_authorized();
					},
				],
			]
		);

		register_rest_route(
			$namespace, '/dashboard/achievements', [
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_achievements' ],
					'permission_callback' => function () {
						return \Mediavine\Permissions::is_user_authorized();
					},
				],
			]
		);

		register_rest_route(
			$namespace, '/dashboard/tips', [
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_tips' ],
					'permission_callback' => function () {
						return \Mediavine\Permissions::is_user_authorized();
					},
				],
			]
		);

		register_rest_route(
			$namespace, '/dashboard/tips/dismiss', [
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'dismiss_tip' ],
					'permission_callback' => function () {
						return \Mediavine\Permissions::is_user_authorized();
					},
				],
			]
		);

		register_rest_route(
			$namespace, '/dashboard/broadcasts', [
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_broadcasts' ],
					'permission_callback' => function () {
						return \Mediavine\Permissions::is_user_authorized();
					},
				],
			]
		);

		register_rest_route(
			$namespace, '/dashboard/broadcasts/dismiss', [
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'dismiss_broadcast' ],
					'permission_callback' => function () {
						return \Mediavine\Permissions::is_user_authorized();
					},
				],
			]
		);
	}

	/**
	 * Get dashboard stats (card counts, review stats).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_stats( $request ) {
		global $wpdb;

		$creations_table = $wpdb->prefix . 'mv_creations';
		$reviews_table   = $wpdb->prefix . 'mv_reviews';

		// Card counts by type.
		$card_counts = $wpdb->get_results(
			"SELECT type, COUNT(*) as count FROM {$creations_table} GROUP BY type",
			ARRAY_A
		);

		$cards = [
			'total'  => 0,
			'recipe' => 0,
			'diy'    => 0,
			'list'   => 0,
		];

		foreach ( $card_counts as $row ) {
			if ( isset( $cards[ $row['type'] ] ) ) {
				$cards[ $row['type'] ] = (int) $row['count'];
			}
			$cards['total'] += (int) $row['count'];
		}

		// Review stats.
		$total_reviews = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$reviews_table}" );
		$avg_rating    = (float) $wpdb->get_var( "SELECT AVG(rating) FROM {$reviews_table}" );

		$week_ago  = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
		$month_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );

		$this_week = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$reviews_table} WHERE created >= %s", $week_ago )
		);
		$this_month = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$reviews_table} WHERE created >= %s", $month_ago )
		);

		// Previous period for trend.
		$two_weeks_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-14 days' ) );
		$last_week     = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$reviews_table} WHERE created >= %s AND created < %s",
				$two_weeks_ago,
				$week_ago
			)
		);

		// Determine trend.
		$trend = 'stable';
		if ( $this_week > $last_week ) {
			$trend = 'up';
		} elseif ( $this_week < $last_week ) {
			$trend = 'down';
		}

		// Recent 5 reviews.
		$recent = $wpdb->get_results(
			"SELECT r.id, r.rating, r.author_name AS reviewer_name, r.review_title, r.created, r.creation,
					c.title as card_title
			 FROM {$reviews_table} r
			 LEFT JOIN {$creations_table} c ON r.creation = c.id
			 ORDER BY r.created DESC
			 LIMIT 5",
			ARRAY_A
		);

		return new \WP_REST_Response( [
			'cards'   => $cards,
			'reviews' => [
				'total'      => $total_reviews,
				'average'    => round( $avg_rating, 1 ),
				'this_week'  => $this_week,
				'this_month' => $this_month,
				'trend'      => $trend,
				'recent'     => $recent ? $recent : [],
			],
		], 200 );
	}

	/**
	 * Get setup checklist state.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_checklist( $request ) {
		global $wpdb;

		$stored = get_option( 'mv_create_setup_checklist', [] );
		if ( ! is_array( $stored ) ) {
			$stored = json_decode( $stored, true );
			if ( ! is_array( $stored ) ) {
				$stored = [];
			}
		}

		$creations_table = $wpdb->prefix . 'mv_creations';

		// 1. Create a card — auto-detect if any cards exist.
		$card_exists  = (bool) $wpdb->get_var( "SELECT 1 FROM {$creations_table} LIMIT 1" );
		$create_card  = $card_exists || ! empty( $stored['create_card'] );

		// 2. Import cards — auto-detect if any card has importer metadata.
		$imported_exists = (bool) $wpdb->get_var(
			"SELECT 1 FROM {$creations_table} WHERE metadata LIKE '%\"import\"%' LIMIT 1"
		);
		$import_cards    = $imported_exists || ! empty( $stored['import_cards'] );

		// 3. Auto-detect: theme has been modified (created != modified in mv_settings).
		$select_theme = false;
		$settings_table = $wpdb->prefix . 'mv_settings';
		$card_style_row = $wpdb->get_row(
			"SELECT created, modified FROM {$settings_table} WHERE slug = 'mv_create_card_style' LIMIT 1",
			ARRAY_A
		);
		if ( $card_style_row && $card_style_row['created'] !== $card_style_row['modified'] ) {
			$select_theme = true;
		}

		// 4. Auto-detect: colors differ from defaults.
		$primary_color   = \Mediavine\Settings::get_setting( 'mv_create_primary_color' );
		$secondary_color = \Mediavine\Settings::get_setting( 'mv_create_secondary_color' );
		$choose_colors   = ( ! empty( $primary_color ) && '#f4f4f4' !== $primary_color ) ||
						   ( ! empty( $secondary_color ) && '#333333' !== $secondary_color && '#333' !== $secondary_color );

		// 5. Auto-detect: registered for Create Studio.
		$registered = ! empty( \Mediavine\Settings::get_setting( 'mv_create_api_token' ) );

		// 6. Auto-detect: Pro subscription.
		$tier   = GateKeeper::get_subscription_tier();
		$is_pro = in_array( $tier, [ GateKeeper::TIER_PRO, GateKeeper::TIER_FREE_PLUS ], true );

		$items = [
			'create_card'     => $create_card,
			'import_cards'    => $import_cards,
			'select_theme'    => $select_theme || ! empty( $stored['select_theme'] ),
			'choose_colors'   => $choose_colors || ! empty( $stored['choose_colors'] ),
			'register_studio' => $registered || ! empty( $stored['register_studio'] ),
			'sign_up_pro'     => $is_pro || ! empty( $stored['sign_up_pro'] ),
		];

		$completed = ! in_array( false, $items, true );

		// If all complete, ensure we mark the type_a achievement.
		if ( $completed ) {
			$achievements = get_option( 'mv_create_achievements', [] );
			if ( ! is_array( $achievements ) ) {
				$achievements = json_decode( $achievements, true );
				if ( ! is_array( $achievements ) ) {
					$achievements = [];
				}
			}
			if ( empty( $achievements['type_a']['unlocked'] ) ) {
				$achievements['type_a'] = [
					'unlocked'    => true,
					'unlocked_at' => gmdate( 'c' ),
				];
				update_option( 'mv_create_achievements', wp_json_encode( $achievements ) );
			}
		}

		return new \WP_REST_Response( [
			'items'     => $items,
			'completed' => $completed,
		], 200 );
	}

	/**
	 * Update a checklist step.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function update_checklist( $request ) {
		$step  = sanitize_text_field( $request->get_param( 'step' ) );
		$value = (bool) $request->get_param( 'value' );

		$valid_steps = [ 'create_card', 'import_cards', 'select_theme', 'choose_colors', 'register_studio', 'sign_up_pro' ];
		if ( ! in_array( $step, $valid_steps, true ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid step' ], 400 );
		}

		$stored = get_option( 'mv_create_setup_checklist', [] );
		if ( ! is_array( $stored ) ) {
			$stored = json_decode( $stored, true );
			if ( ! is_array( $stored ) ) {
				$stored = [];
			}
		}

		$stored[ $step ] = $value;
		update_option( 'mv_create_setup_checklist', wp_json_encode( $stored ) );

		return $this->get_checklist( $request );
	}

	/**
	 * Get achievement state.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_achievements( $request ) {
		// TEMP: mock data for dashboard development.
		if ( defined( 'MV_CREATE_MOCK_ACHIEVEMENTS' ) && MV_CREATE_MOCK_ACHIEVEMENTS ) {
			return new \WP_REST_Response( [
				'create_er'         => [ 'tier' => 'bronze', 'count' => 221, 'unlocked_at' => '2026-01-27T00:00:00+00:00' ],
				'review_magnet'        => [ 'tier' => 'bronze', 'count' => 51, 'unlocked_at' => '2026-01-27T00:00:00+00:00' ],
				'product_pusher'          => [ 'tier' => 'bronze', 'count' => 42, 'unlocked_at' => '2026-01-27T00:00:00+00:00' ],
				'studious_creator'     => [ 'unlocked' => true, 'unlocked_at' => '2026-01-27T00:00:00+00:00' ],
				'pro_create_er'        => [ 'unlocked' => true, 'unlocked_at' => '2026-01-27T00:00:00+00:00' ],
				'type_a'       => [ 'unlocked' => true, 'unlocked_at' => '2026-01-27T00:00:00+00:00', 'count' => 6, 'total' => 6 ],
				'bougie_branding'           => [ 'unlocked' => true, 'unlocked_at' => '2026-01-27T00:00:00+00:00' ],
				'moderator'            => [ 'unlocked' => true, 'unlocked_at' => '2026-01-27T00:00:00+00:00' ],
				'amazonian'     => [ 'unlocked' => true, 'unlocked_at' => '2026-01-27T00:00:00+00:00' ],
				'global_citizen' => [ 'unlocked' => true, 'unlocked_at' => '2026-01-27T00:00:00+00:00' ],
				'homecoming'           => [ 'unlocked' => true, 'unlocked_at' => '2026-01-27T00:00:00+00:00' ],
				'jack_of_all_cards'    => [ 'unlocked' => true, 'unlocked_at' => '2026-01-27T00:00:00+00:00' ],
			], 200 );
		}

		global $wpdb;

		$creations_table = $wpdb->prefix . 'mv_creations';
		$reviews_table   = $wpdb->prefix . 'mv_reviews';

		$stored = get_option( 'mv_create_achievements', [] );
		if ( ! is_array( $stored ) ) {
			$stored = json_decode( $stored, true );
			if ( ! is_array( $stored ) ) {
				$stored = [];
			}
		}

		// Card Creator - tiered.
		$card_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$creations_table}" );
		$card_tier  = null;
		if ( $card_count >= 1000 ) {
			$card_tier = 'gold';
		} elseif ( $card_count >= 250 ) {
			$card_tier = 'silver';
		} elseif ( $card_count >= 50 ) {
			$card_tier = 'bronze';
		}

		$stored_card      = isset( $stored['create_er'] ) ? $stored['create_er'] : [];
		$stored_card_tier = isset( $stored_card['tier'] ) ? $stored_card['tier'] : null;

		if ( $card_tier !== $stored_card_tier && null !== $card_tier ) {
			$stored['create_er'] = [
				'tier'        => $card_tier,
				'count'       => $card_count,
				'unlocked_at' => gmdate( 'c' ),
			];
		} else {
			$stored['create_er'] = array_merge(
				[ 'tier' => null, 'count' => 0, 'unlocked_at' => null ],
				$stored_card,
				[ 'count' => $card_count ]
			);
		}

		// Review Magnet - tiered.
		$review_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$reviews_table}" );
		$review_tier  = null;
		if ( $review_count >= 1000 ) {
			$review_tier = 'gold';
		} elseif ( $review_count >= 250 ) {
			$review_tier = 'silver';
		} elseif ( $review_count >= 50 ) {
			$review_tier = 'bronze';
		}

		$stored_review      = isset( $stored['review_magnet'] ) ? $stored['review_magnet'] : [];
		$stored_review_tier = isset( $stored_review['tier'] ) ? $stored_review['tier'] : null;

		if ( $review_tier !== $stored_review_tier && null !== $review_tier ) {
			$stored['review_magnet'] = [
				'tier'        => $review_tier,
				'count'       => $review_count,
				'unlocked_at' => gmdate( 'c' ),
			];
		} else {
			$stored['review_magnet'] = array_merge(
				[ 'tier' => null, 'count' => 0, 'unlocked_at' => null ],
				$stored_review,
				[ 'count' => $review_count ]
			);
		}

		// Studio Connected - single tier.
		$registered = ! empty( \Mediavine\Settings::get_setting( 'mv_create_api_token' ) );
		if ( $registered && empty( $stored['studious_creator']['unlocked'] ) ) {
			$stored['studious_creator'] = [
				'unlocked'    => true,
				'unlocked_at' => gmdate( 'c' ),
			];
		} elseif ( ! isset( $stored['studious_creator'] ) ) {
			$stored['studious_creator'] = [ 'unlocked' => false, 'unlocked_at' => null ];
		}

		// Pro Publisher - single tier.
		$tier   = GateKeeper::get_subscription_tier();
		$is_pro = in_array( $tier, [ GateKeeper::TIER_PRO, GateKeeper::TIER_FREE_PLUS ], true );
		if ( $is_pro && empty( $stored['pro_create_er']['unlocked'] ) ) {
			$stored['pro_create_er'] = [
				'unlocked'    => true,
				'unlocked_at' => gmdate( 'c' ),
			];
		} elseif ( ! isset( $stored['pro_create_er'] ) ) {
			$stored['pro_create_er'] = [ 'unlocked' => false, 'unlocked_at' => null ];
		}

		// Setup Complete - progress based on checklist items.
		$checklist_stored = get_option( 'mv_create_setup_checklist', [] );
		if ( ! is_array( $checklist_stored ) ) {
			$checklist_stored = json_decode( $checklist_stored, true );
			if ( ! is_array( $checklist_stored ) ) {
				$checklist_stored = [];
			}
		}

		$settings_table  = $wpdb->prefix . 'mv_settings';
		$checklist_items = [
			'create_card'     => (bool) $wpdb->get_var( "SELECT 1 FROM {$creations_table} LIMIT 1" ) || ! empty( $checklist_stored['create_card'] ),
			'import_cards'    => (bool) $wpdb->get_var( "SELECT 1 FROM {$creations_table} WHERE metadata LIKE '%\"import\"%' LIMIT 1" ) || ! empty( $checklist_stored['import_cards'] ),
			'select_theme'    => (bool) $wpdb->get_var( "SELECT 1 FROM {$settings_table} WHERE slug = 'mv_create_card_style' AND created != modified LIMIT 1" ) || ! empty( $checklist_stored['select_theme'] ),
			'choose_colors'   => ( ! empty( \Mediavine\Settings::get_setting( 'mv_create_primary_color' ) ) && '#f4f4f4' !== \Mediavine\Settings::get_setting( 'mv_create_primary_color' ) ) ||
								 ( ! empty( \Mediavine\Settings::get_setting( 'mv_create_secondary_color' ) ) && '#333333' !== \Mediavine\Settings::get_setting( 'mv_create_secondary_color' ) && '#333' !== \Mediavine\Settings::get_setting( 'mv_create_secondary_color' ) ) ||
								 ! empty( $checklist_stored['choose_colors'] ),
			'register_studio' => ! empty( \Mediavine\Settings::get_setting( 'mv_create_api_token' ) ) || ! empty( $checklist_stored['register_studio'] ),
			'sign_up_pro'     => $is_pro || ! empty( $checklist_stored['sign_up_pro'] ),
		];

		$checklist_count = count( array_filter( $checklist_items ) );
		$checklist_total = count( $checklist_items );

		if ( ! isset( $stored['type_a'] ) ) {
			$stored['type_a'] = [ 'unlocked' => false, 'unlocked_at' => null ];
		}
		$stored['type_a']['count'] = $checklist_count;
		$stored['type_a']['total'] = $checklist_total;

		// Product Pro - tiered (10/100/250).
		$products_table = $wpdb->prefix . 'mv_products';
		$product_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$products_table}" );
		$product_tier   = null;
		if ( $product_count >= 250 ) {
			$product_tier = 'gold';
		} elseif ( $product_count >= 75 ) {
			$product_tier = 'silver';
		} elseif ( $product_count >= 15 ) {
			$product_tier = 'bronze';
		}

		$stored_product      = isset( $stored['product_pusher'] ) ? $stored['product_pusher'] : [];
		$stored_product_tier = isset( $stored_product['tier'] ) ? $stored_product['tier'] : null;

		if ( $product_tier !== $stored_product_tier && null !== $product_tier ) {
			$stored['product_pusher'] = [
				'tier'        => $product_tier,
				'count'       => $product_count,
				'unlocked_at' => gmdate( 'c' ),
			];
		} else {
			$stored['product_pusher'] = array_merge(
				[ 'tier' => null, 'count' => 0, 'unlocked_at' => null ],
				$stored_product,
				[ 'count' => $product_count ]
			);
		}

		// Aesthetics - single (both primary AND secondary colors differ from defaults).
		$primary_color   = \Mediavine\Settings::get_setting( 'mv_create_primary_color' );
		$secondary_color = \Mediavine\Settings::get_setting( 'mv_create_secondary_color' );
		$colors_changed  = ( ! empty( $primary_color ) && '#f4f4f4' !== $primary_color ) &&
						   ( ! empty( $secondary_color ) && '#333333' !== $secondary_color && '#333' !== $secondary_color );
		if ( $colors_changed && empty( $stored['bougie_branding']['unlocked'] ) ) {
			$stored['bougie_branding'] = [
				'unlocked'    => true,
				'unlocked_at' => gmdate( 'c' ),
			];
		} elseif ( ! isset( $stored['bougie_branding'] ) ) {
			$stored['bougie_branding'] = [ 'unlocked' => false, 'unlocked_at' => null ];
		}

		// Moderator - single (admin edited or deleted a review).
		// The unlock is also triggered directly by the reviews API on edit/delete.
		// Here we also check if any reviews have been edited by admin.
		if ( empty( $stored['moderator']['unlocked'] ) ) {
			$admin_edited = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$reviews_table} WHERE edited_by_admin = 1"
			);
			if ( $admin_edited > 0 ) {
				$stored['moderator'] = [
					'unlocked'    => true,
					'unlocked_at' => gmdate( 'c' ),
				];
			}
		}
		if ( ! isset( $stored['moderator'] ) ) {
			$stored['moderator'] = [ 'unlocked' => false, 'unlocked_at' => null ];
		}

		// Amazon Connected - single (all 4 PA-API settings configured).
		$amazon_enabled    = \Mediavine\Settings::get_setting( 'mv_create_enable_amazon' );
		$amazon_access_key = \Mediavine\Settings::get_setting( 'mv_create_paapi_access_key' );
		$amazon_secret_key = \Mediavine\Settings::get_setting( 'mv_create_paapi_secret_key' );
		$amazon_tag        = \Mediavine\Settings::get_setting( 'mv_create_paapi_tag' );
		$amazonian  = ! empty( $amazon_enabled ) && ! empty( $amazon_access_key ) &&
							 ! empty( $amazon_secret_key ) && ! empty( $amazon_tag );
		if ( $amazonian && empty( $stored['amazonian']['unlocked'] ) ) {
			$stored['amazonian'] = [
				'unlocked'    => true,
				'unlocked_at' => gmdate( 'c' ),
			];
		} elseif ( ! isset( $stored['amazonian'] ) ) {
			$stored['amazonian'] = [ 'unlocked' => false, 'unlocked_at' => null ];
		}

		// Internationalization - single (unit conversion enabled).
		$unit_conversion = \Mediavine\Settings::get_setting( 'mv_create_enable_unit_conversion', false );
		if ( ! empty( $unit_conversion ) && empty( $stored['global_citizen']['unlocked'] ) ) {
			$stored['global_citizen'] = [
				'unlocked'    => true,
				'unlocked_at' => gmdate( 'c' ),
			];
		} elseif ( ! isset( $stored['global_citizen'] ) ) {
			$stored['global_citizen'] = [ 'unlocked' => false, 'unlocked_at' => null ];
		}

		// Homecoming - single (imported cards from another plugin).
		$imported_exists = (bool) $wpdb->get_var(
			"SELECT 1 FROM {$creations_table} WHERE metadata LIKE '%\"import\"%' LIMIT 1"
		);
		if ( $imported_exists && empty( $stored['homecoming']['unlocked'] ) ) {
			$stored['homecoming'] = [
				'unlocked'    => true,
				'unlocked_at' => gmdate( 'c' ),
			];
		} elseif ( ! isset( $stored['homecoming'] ) ) {
			$stored['homecoming'] = [ 'unlocked' => false, 'unlocked_at' => null ];
		}

		// Jack of All Cards - single (at least one of each card type: recipe, diy, list).
		$type_counts = $wpdb->get_results(
			"SELECT type, COUNT(*) as count FROM {$creations_table} WHERE type IN ('recipe', 'diy', 'list') GROUP BY type",
			ARRAY_A
		);
		$has_all_types = count( $type_counts ) >= 3;
		if ( $has_all_types && empty( $stored['jack_of_all_cards']['unlocked'] ) ) {
			$stored['jack_of_all_cards'] = [
				'unlocked'    => true,
				'unlocked_at' => gmdate( 'c' ),
			];
		} elseif ( ! isset( $stored['jack_of_all_cards'] ) ) {
			$stored['jack_of_all_cards'] = [ 'unlocked' => false, 'unlocked_at' => null ];
		}

		// Checklists Enabled - single (interactive checklists feature enabled).
		$checklists_enabled = \Mediavine\Settings::get_setting( 'mv_create_enable_checklists', false );
		if ( ! empty( $checklists_enabled ) && empty( $stored['checklists_enabled']['unlocked'] ) ) {
			$stored['checklists_enabled'] = [
				'unlocked'    => true,
				'unlocked_at' => gmdate( 'c' ),
			];
		} elseif ( ! isset( $stored['checklists_enabled'] ) ) {
			$stored['checklists_enabled'] = [ 'unlocked' => false, 'unlocked_at' => null ];
		}

		// Servings Adjustment - single (servings adjustment feature enabled).
		$servings_enabled = \Mediavine\Settings::get_setting( 'mv_create_enable_servings_adjustment', false );
		if ( ! empty( $servings_enabled ) && empty( $stored['servings_adjustment']['unlocked'] ) ) {
			$stored['servings_adjustment'] = [
				'unlocked'    => true,
				'unlocked_at' => gmdate( 'c' ),
			];
		} elseif ( ! isset( $stored['servings_adjustment'] ) ) {
			$stored['servings_adjustment'] = [ 'unlocked' => false, 'unlocked_at' => null ];
		}

		// Party Pooper - single (confetti shortcut disabled).
		$confetti_setting = \Mediavine\Settings::get_setting( 'mv_create_enable_confetti_shortcut', true );
		$confetti_disabled = empty( $confetti_setting ) || '0' === $confetti_setting || false === $confetti_setting;
		if ( $confetti_disabled && empty( $stored['party_pooper']['unlocked'] ) ) {
			$stored['party_pooper'] = [
				'unlocked'    => true,
				'unlocked_at' => gmdate( 'c' ),
			];
		} elseif ( ! isset( $stored['party_pooper'] ) ) {
			$stored['party_pooper'] = [ 'unlocked' => false, 'unlocked_at' => null ];
		}

		update_option( 'mv_create_achievements', wp_json_encode( $stored ) );

		return new \WP_REST_Response( $stored, 200 );
	}

	/**
	 * Get tips/announcements feed.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_tips( $request ) {
		$cached = false || get_transient( 'mv_create_dashboard_tips' );
		if ( false !== $cached ) {
			$tips = json_decode( $cached, true );
			if ( is_array( $tips ) ) {
				return new \WP_REST_Response( $this->prepare_tips( $tips ), 200 );
			}
		}

		// Fetch from Create Studio (use $services_api_url which resolves correctly inside Docker).
		$feed_url = rtrim( \Mediavine\Create\Plugin::$services_api_url, '/' ) . '/plugin/tips';
		$response = wp_remote_get( $feed_url, [
			'timeout' => 5,
			'headers' => [ 'Accept' => 'application/json' ],
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// return new \WP_REST_Response( $this->prepare_tips( $this->get_fallback_tips() ), 200 );
			return new \WP_REST_Response( [ 'error' => 'Remote fetch failed' ], 200 );
		}

		$body = wp_remote_retrieve_body( $response );
		$tips = json_decode( $body, true );

		if ( ! is_array( $tips ) ) {
			// return new \WP_REST_Response( $this->prepare_tips( $this->get_fallback_tips() ), 200 );
			return new \WP_REST_Response( [ 'error' => 'Invalid response body' ], 200 );
		}

		// Cache for 1 hour.
		set_transient( 'mv_create_dashboard_tips', $body, HOUR_IN_SECONDS );

		return new \WP_REST_Response( $this->prepare_tips( $tips ), 200 );
	}

	/**
	 * Dismiss a tip for the current user.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function dismiss_tip( $request ) {
		$tip_id = sanitize_text_field( $request->get_param( 'id' ) );
		if ( empty( $tip_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'Missing tip ID' ], 400 );
		}

		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, 'mv_create_dismissed_tips', true );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = [];
		}

		if ( ! in_array( $tip_id, $dismissed, true ) ) {
			$dismissed[] = $tip_id;
			update_user_meta( $user_id, 'mv_create_dismissed_tips', $dismissed );
		}

		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Filter out tips dismissed by the current user.
	 *
	 * @param array $tips
	 * @return array
	 */
	private function filter_dismissed_tips( $tips ) {
		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, 'mv_create_dismissed_tips', true );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = [];
		}

		return array_values(
			array_filter( $tips, function( $tip ) use ( $dismissed ) {
				return ! in_array( $tip['id'], $dismissed, true );
			} )
		);
	}

	/**
	 * Filter out expired tips.
	 *
	 * @param array $tips
	 * @return array
	 */
	private function filter_expired_tips( $tips ) {
		$now = time();
		return array_values(
			array_filter( $tips, function( $tip ) use ( $now ) {
				if ( empty( $tip['expires_at'] ) ) {
					return true;
				}
				return strtotime( $tip['expires_at'] ) > $now;
			} )
		);
	}

	/**
	 * Fallback tips shown when the remote endpoint is unreachable.
	 *
	 * @return array
	 */
	private function get_fallback_tips() {
		return [
			[
				'id'    => 'fallback-structured-data',
				'type'  => 'tip',
				'title' => __( 'Add structured data to every post', 'mediavine' ),
				'body'  => __( 'Embedding a Create card adds Recipe or HowTo schema automatically, helping your content appear in Google rich results.', 'mediavine' ),
			],
			[
				'id'    => 'fallback-list-cards',
				'type'  => 'tip',
				'title' => __( 'Use List cards for roundups', 'mediavine' ),
				'body'  => __( 'List cards generate ItemList schema and link to your other content — great for roundup posts.', 'mediavine' ),
			],
			[
				'id'    => 'fallback-customize-colors',
				'type'  => 'tip',
				'title' => __( 'Customize your card colors', 'mediavine' ),
				'body'  => __( 'Match your cards to your brand by setting primary and secondary colors in Settings.', 'mediavine' ),
				'path'  => 'settings#appearance',
			],
			[
				'id'    => 'fallback-reviews',
				'type'  => 'tip',
				'title' => __( 'Add reviews to boost engagement', 'mediavine' ),
				'body'  => __( 'Enable reviews on your cards to let readers rate your recipes. Higher engagement signals help with SEO.', 'mediavine' ),
			],
			[
				'id'    => 'fallback-connect-studio',
				'type'  => 'tip',
				'title' => __( 'Connect to Create Studio', 'mediavine' ),
				'body'  => __( 'Link your site to Create Studio for analytics, remote settings, and Pro features.', 'mediavine' ),
				'path'  => 'settings#create-studio',
			],
		];
	}

	/**
	 * Run all tip filters: resolve paths, remove expired, remove dismissed.
	 *
	 * @param array $tips
	 * @return array
	 */
	private function prepare_tips( $tips ) {
		$tips = $this->resolve_tip_paths( $tips );
		$tips = $this->filter_expired_tips( $tips );
		$tips = $this->filter_dismissed_tips( $tips );
		return $tips;
	}

	/**
	 * Resolve `path` fields into full `url` values.
	 *
	 * Studio sends a `path` shortcut (e.g. "settings#appearance") which the
	 * plugin resolves to the site's actual WP admin URL. If a tip already has
	 * a `url`, it is left untouched.
	 *
	 * @param array $tips
	 * @return array
	 */
	private function resolve_tip_paths( $tips ) {
		$admin_url = admin_url();

		$path_map = [
			'settings'  => 'edit.php?page=settings&post_type=mv_create',
			'dashboard' => 'edit.php?post_type=mv_create&page=create_dashboard',
			'cards'     => 'edit.php?post_type=mv_create',
			'new'       => 'post-new.php?post_type=mv_create',
			'new-list'  => 'post-new.php?post_type=mv_create&cardType=list',
			'new-diy'   => 'post-new.php?post_type=mv_create&cardType=diy',
			'new-recipe'=> 'post-new.php?post_type=mv_create&cardType=recipe',
			'plugins'   => 'plugins.php',
			'updates'   => 'update-core.php',
		];

		return array_map( function( $tip ) use ( $admin_url, $path_map ) {
			if ( ! empty( $tip['url'] ) || empty( $tip['path'] ) ) {
				return $tip;
			}

			$path = $tip['path'];
			$hash = '';

			// Separate hash fragment (e.g. "settings#appearance" → "settings" + "#appearance").
			if ( false !== strpos( $path, '#' ) ) {
				list( $path, $hash ) = explode( '#', $path, 2 );
				$hash = '#' . $hash;
			}

			if ( false !== strpos( $path, '?' ) ) {
				list( $path, $query ) = explode( '?', $path, 2 );
				$hash = '&' . $query;
			}

			if ( isset( $path_map[ $path ] ) ) {
				$tip['url'] = $admin_url . $path_map[ $path ] . $hash;
			}

			unset( $tip['path'] );
			return $tip;
		}, $tips );
	}

	/**
	 * Get broadcasts feed.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_broadcasts( $request ) {
		$cached = get_transient( 'mv_create_broadcasts' );
		if ( false !== $cached ) {
			$broadcasts = json_decode( $cached, true );
			if ( is_array( $broadcasts ) ) {
				return new \WP_REST_Response( $this->prepare_broadcasts( $broadcasts ), 200 );
			}
		}

		$feed_url = rtrim( Plugin::$services_api_url, '/' ) . '/plugin/broadcasts?tier=' . GateKeeper::get_subscription_tier() . '&create_version=' . Plugin::VERSION;
		$response = wp_remote_get( $feed_url, [
			'timeout' => 5,
			'headers' => [ 'Accept' => 'application/json' ],
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_REST_Response( [ 'error' => 'Remote fetch failed' ], 200 );
		}

		$body       = wp_remote_retrieve_body( $response );
		$broadcasts = json_decode( $body, true );

		if ( ! is_array( $broadcasts ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid response body' ], 200 );
		}

		$ttl = Plugin::is_dev_mode() ? 3 * MINUTE_IN_SECONDS : DAY_IN_SECONDS;
		set_transient( 'mv_create_broadcasts', $body, $ttl );

		return new \WP_REST_Response( $this->prepare_broadcasts( $broadcasts ), 200 );
	}

	/**
	 * Dismiss a broadcast for the current user.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function dismiss_broadcast( $request ) {
		$broadcast_id = sanitize_text_field( $request->get_param( 'id' ) );
		if ( empty( $broadcast_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'Missing broadcast ID' ], 400 );
		}

		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, 'mv_create_dismissed_broadcasts', true );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = [];
		}

		if ( ! in_array( $broadcast_id, $dismissed, true ) ) {
			$dismissed[] = $broadcast_id;
			update_user_meta( $user_id, 'mv_create_dismissed_broadcasts', $dismissed );
		}

		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Run all broadcast filters: resolve paths, remove expired, remove dismissed.
	 *
	 * @param array $broadcasts
	 * @return array
	 */
	private function prepare_broadcasts( $broadcasts ) {
		$broadcasts = $this->resolve_tip_paths( $broadcasts );
		$broadcasts = $this->filter_expired_tips( $broadcasts );
		$broadcasts = $this->filter_dismissed_broadcasts( $broadcasts );
		return $broadcasts;
	}

	/**
	 * Filter out broadcasts dismissed by the current user.
	 *
	 * @param array $broadcasts
	 * @return array
	 */
	private function filter_dismissed_broadcasts( $broadcasts ) {
		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, 'mv_create_dismissed_broadcasts', true );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = [];
		}

		return array_values(
			array_filter( $broadcasts, function( $broadcast ) use ( $dismissed ) {
				return ! in_array( (string) $broadcast['id'], $dismissed, true );
			} )
		);
	}

	/**
	 * Get the single highest-priority active broadcast for the current user.
	 *
	 * Used by Broadcast_Notice for the admin banner (no REST context needed).
	 *
	 * @return array|null Broadcast data or null if none active.
	 */
	public static function get_active_broadcast() {
		// Request-scoped static cache to avoid multiple remote calls per page load.
		static $result_cache = null;
		static $cache_checked = false;
		if ( $cache_checked ) {
			return $result_cache;
		}
		$cache_checked = true;

		$cached = get_transient( 'mv_create_broadcasts' );
		if ( false !== $cached ) {
			$broadcasts = json_decode( $cached, true );
		}

		if ( empty( $broadcasts ) || ! is_array( $broadcasts ) ) {
			$feed_url = rtrim( Plugin::$services_api_url, '/' ) . '/plugin/broadcasts?tier=' . GateKeeper::get_subscription_tier() . '&create_version=' . Plugin::VERSION;
			$response = wp_remote_get( $feed_url, [
				'timeout' => 5,
				'headers' => [ 'Accept' => 'application/json' ],
			] );

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}

			$body       = wp_remote_retrieve_body( $response );
			$broadcasts = json_decode( $body, true );

			if ( ! is_array( $broadcasts ) ) {
				return null;
			}

			$ttl = Plugin::is_dev_mode() ? 3 * MINUTE_IN_SECONDS : DAY_IN_SECONDS;
			set_transient( 'mv_create_broadcasts', $body, $ttl );
		}

		// Filter expired.
		$now        = time();
		$broadcasts = array_filter( $broadcasts, function( $broadcast ) use ( $now ) {
			if ( empty( $broadcast['expires_at'] ) ) {
				return true;
			}
			return strtotime( $broadcast['expires_at'] ) > $now;
		} );

		// Filter dismissed.
		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, 'mv_create_dismissed_broadcasts', true );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = [];
		}
		$broadcasts = array_filter( $broadcasts, function( $broadcast ) use ( $dismissed ) {
			return ! in_array( (string) $broadcast['id'], $dismissed, true );
		} );

		$broadcasts = array_values( $broadcasts );

		$result_cache = ! empty( $broadcasts ) ? $broadcasts[0] : null;
		return $result_cache;
	}
}
